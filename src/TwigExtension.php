<?php

declare(strict_types=1);

/*
 * Twig plugin for Kint
 * Copyright (C) 2017 Jonathan Vollebregt
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace Kint\Twig;

use Kint\Kint;
use Kint\Parser\Parser;
use Kint\Renderer\PlainRenderer;
use Kint\Renderer\RichRenderer;
use Kint\Utils;
use Kint\Value\Context\BaseContext;
use ReflectionObject;
use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\Source;
use Twig\Template;
use Twig\TwigFunction;
use TypeError;
use ValueError;

/** @psalm-api */
class TwigExtension extends AbstractExtension
{
    /** @psalm-var Kint[] */
    protected array $aliases;
    protected bool $frozen = false;

    public function __construct(?array $aliases = null)
    {
        if (null !== $aliases) {
            $this->aliases = $aliases;
        } else {
            $statics = Kint::getStatics();

            $rich = new Kint(new Parser(), new RichRenderer());
            $rich->setStatesFromStatics($statics);

            $plain = new Kint(new Parser(), new PlainRenderer());
            $plain->setStatesFromStatics($statics);

            $this->aliases = [
                'd' => $rich,
                's' => $plain,
            ];
        }
    }

    public function getAliases(): array
    {
        return $this->aliases;
    }

    public function setAliases(array $aliases): void
    {
        if ($this->frozen) {
            return;
        }

        $this->aliases = $aliases;
    }

    /**
     * @psalm-suppress UndefinedDocblockClass
     * @psalm-suppress ImplementedReturnTypeMismatch
     *
     * @psalm-return list<TwigFunction>
     */
    public function getFunctions(): array
    {
        $opts = [
            'is_safe' => ['html'],
            'is_variadic' => true,
            'needs_context' => true,
            'needs_environment' => true,
        ];

        $ret = [];

        foreach ($this->aliases as $alias => $_) {
            $ret[] = new TwigFunction(
                $alias,
                function (Environment $env, array $context, array $args = []) use ($alias) {
                    return $this->dump($alias, $env, $context, $args);
                },
                $opts
            );
        }

        $this->frozen = true;

        return $ret;
    }

    /**
     * Dumper function sets return and mode.
     */
    protected function dump(string $alias, Environment $env, array $context, array $args = []): string
    {
        if (!$env->isDebug()) {
            return '';
        }

        $bases = [];
        $vars = [];

        if ($args) {
            $i = 0;
            foreach ($args as $arg) {
                $bases[$i] = new BaseContext('$'.$i);
                $vars[$i] = $arg;
                ++$i;
            }
        } else {
            foreach ($context as $key => $arg) {
                $bases[$key] = new BaseContext($key);
                $vars[$key] = $arg;
            }
        }

        $info = $this->getCallInfo($env);
        if (isset($info['callee']) && !isset($info['callee']['function'])) {
            $info['callee']['function'] = $alias;
            $info['trace'][0]['function'] = $alias;
        }
        $this->aliases[$alias]->getRenderer()->setCallInfo($info);

        return $this->aliases[$alias]->dumpAll($vars, $bases);
    }

    protected function getCallInfo(Environment $env): array
    {
        $trace = \debug_backtrace();
        $miniTrace = [];

        foreach ($trace as $index => $frame) {
            // Ignore everything inside the extension
            if (isset($frame['object']) && $frame['object'] === $this) {
                $miniTrace = [];
            }

            $previous_frame = $trace[$index + 1] ?? null;
            $caller_object = $previous_frame['object'] ?? null;
            $template = null;
            if ($caller_object instanceof Template) {
                $r = new ReflectionObject($caller_object);
                if (isset($frame['file'], $frame['line']) && $r->getFileName() === $frame['file']) {
                    $template = $caller_object;
                } else {
                    // If this is an internal method in the base Template class skip it
                    continue;
                }
            }

            // If this frame is from a template
            if ($template) {
                // Since Twig\Template is internal we'll skip call info
                // altogether if anything changes unexpectedly in the API
                $source = null;
                if (\is_callable([$caller_object, 'getSourceContext'])) {
                    /** @psalm-suppress InternalMethod */
                    $source = $template->getSourceContext();
                }
                if (!$source instanceof Source) {
                    // @codeCoverageIgnoreStart
                    return [
                        'params' => null,
                        'modifiers' => [],
                        'callee' => null,
                        'caller' => null,
                        'trace' => [],
                    ];
                    // @codeCoverageIgnoreEnd
                }

                /**
                 * We checked these earlier.
                 *
                 * @psalm-var string $frame['file']
                 * @psalm-var int $frame['line']
                 */
                $lineno = $this->getTwigLineFromPhpLine($env, $source, $frame['file'], $frame['line']);

                $path = $source->getPath();
                if (!\strlen($path)) {
                    $path = '<TWIG>:'.$source->getName();
                }

                // Construct the fake frame
                $miniTrace[] = [
                    'line' => $lineno,
                    'file' => $path,
                ];
            } elseif (!Utils::traceFrameIsListed($frame, ['spl_autoload_call'])) {
                $miniTrace[] = $frame;
            }
        }

        $callee = \reset($miniTrace);
        if (!(bool) $callee) {
            $callee = null;
        }
        $caller = \next($miniTrace);
        if (!(bool) $caller) {
            $caller = null;
        }

        foreach ($miniTrace as $index => $frame) {
            if ((0 === $index && $callee === $frame) || isset($frame['file'], $frame['line'])) {
                unset($frame['object'], $frame['args']);
                $miniTrace[$index] = $frame;
            } else {
                unset($miniTrace[$index]);
            }
        }

        $miniTrace = \array_values($miniTrace);

        return [
            'params' => null,
            'modifiers' => [],
            'callee' => $callee,
            'caller' => $caller,
            'trace' => $miniTrace,
        ];
    }

    protected function getTwigLineFromPhpLine(Environment $env, Source $source, string $path, int $line): ?int
    {
        $phpsource = false;

        try {
            // Avoid recompiling twig templates if we can
            if (@\is_readable($path)) {
                $phpsource = @\file_get_contents($path);
            }
            if (false === $phpsource) {
                $phpsource = $env->compileSource($source);
            }
        } catch (TypeError|ValueError $e) {// @codeCoverageIgnore
            // TypeError only possible in PHP 7, ValueError from 8.6+
            return null; // @codeCoverageIgnore
        }

        // Pull the twig line number from the PHP source code
        $lines = \explode("\n", $phpsource, $line);
        for ($i = $line - 2; $i >= 0; --$i) {
            if (\preg_match('%^\\W+// line (\\d+)$%', $lines[$i], $match)) {
                return (int) $match[1];
            }
        }

        return null; // @codeCoverageIgnore
    }
}

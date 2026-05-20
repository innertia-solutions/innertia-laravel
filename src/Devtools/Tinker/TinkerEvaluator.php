<?php

namespace Innertia\Devtools\Tinker;

class TinkerEvaluator
{
    /**
     * Evaluates $code in a closure scope that has access to the session's
     * previously defined variables. Variables created or modified during eval
     * are persisted back to the session (serializable values only).
     *
     * Returns: ['output' => string, 'return' => mixed, 'error' => string|null]
     */
    public function evaluate(TinkerSession $session, string $code): array
    {
        // Run inside a closure so get_defined_vars() captures exactly
        // what we inject + what eval creates, nothing from the outer frame.
        return (function () use ($session, $code) {
            extract($session->variables(), EXTR_SKIP);

            ob_start();
            $__return = null;
            $__error  = null;

            try {
                $__return = eval($code);
            } catch (\ParseError $__e) {
                $__error = 'ParseError: ' . $__e->getMessage();
            } catch (\Throwable $__e) {
                $__error = get_class($__e) . ': ' . $__e->getMessage();
            }

            $__output = ob_get_clean() ?: '';

            // Capture everything in this closure's scope after eval ran
            $__all = get_defined_vars();

            // Strip evaluator internals — keep only user variables
            foreach (['session', 'code', '__return', '__error', '__output', '__all', '__e'] as $__k) {
                unset($__all[$__k]);
            }

            // Persist only serializable values
            $__saveable = [];
            foreach ($__all as $__key => $__val) {
                try {
                    serialize($__val);
                    $__saveable[$__key] = $__val;
                } catch (\Throwable) {
                    // Non-serializable (closures, resources) — skip silently
                }
            }

            $session->save($__saveable);

            return [
                'output' => $__output,
                'return' => $__return,
                'error'  => $__error,
            ];
        })();
    }
}

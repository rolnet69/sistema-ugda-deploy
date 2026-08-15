<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SanitizeTextInput
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->query->replace($this->sanitizeArray($request->query->all()));
        $request->merge($this->sanitizeArray($request->request->all()));

        return $next($request);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function sanitizeArray(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $values[$key] = $this->sanitizeArray($value);
                continue;
            }

            if (is_string($value)) {
                $values[$key] = $this->sanitizeString($value);
            }
        }

        return $values;
    }

    private function sanitizeString(string $value): string
    {
        $value = preg_replace('/<\s*script\b[^>]*>.*?<\s*\/\s*script\s*>/isu', '', $value) ?? $value;
        $value = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/iu', '', $value) ?? $value;
        $value = preg_replace('/\b(?:javascript|vbscript)\s*:/iu', '', $value) ?? $value;
        $value = strip_tags($value);
        $value = str_replace(['<', '>'], '', $value);

        return preg_replace(
            '/[\x{1F000}-\x{1FAFF}\x{1F300}-\x{1F5FF}\x{1F600}-\x{1F64F}\x{1F680}-\x{1F6FF}\x{2600}-\x{27BF}\x{FE0F}\x{20E3}]/u',
            '',
            $value
        ) ?? $value;
    }
}

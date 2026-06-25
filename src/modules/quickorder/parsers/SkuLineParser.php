<?php

namespace totalwebcreations\b2bcommerce\modules\quickorder\parsers;

class SkuLineParser
{
    private const DELIMITERS = ["\t", ',', ';'];

    /**
     * @return array{
     *     lines: array<int, array{sku: string, qty: int}>,
     *     errors: array<int, string>
     * } — both maps keyed by the 1-based line number of the original input
     */
    public function parse(string $input): array
    {
        $lines = [];
        $errors = [];
        $seen = [];

        foreach ($this->splitIntoLines($input) as $index => $rawLine) {
            $lineNumber = $index + 1;
            $trimmed = trim($rawLine);

            if ($trimmed === '') {
                continue;
            }

            [$sku, $qty] = $this->splitLine($trimmed);

            if ($sku === '') {
                $errors[$lineNumber] = 'Missing SKU';

                continue;
            }

            if ($qty === null) {
                $quantity = 1;
            } elseif ($this->isValidQuantity($qty)) {
                $quantity = (int) $qty;
            } else {
                $errors[$lineNumber] = 'Invalid quantity';

                continue;
            }

            $key = mb_strtolower($sku);

            if (isset($seen[$key])) {
                $lines[$seen[$key]]['qty'] += $quantity;

                continue;
            }

            $seen[$key] = $lineNumber;
            $lines[$lineNumber] = ['sku' => $sku, 'qty' => $quantity];
        }

        return ['lines' => $lines, 'errors' => $errors];
    }

    /** @return array<int, string> */
    private function splitIntoLines(string $input): array
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $input);

        return explode("\n", $normalized);
    }

    /** @return array{0: string, 1: ?string} */
    private function splitLine(string $line): array
    {
        foreach (self::DELIMITERS as $delimiter) {
            if (str_contains($line, $delimiter)) {
                $parts = explode($delimiter, $line, 2);

                return [trim($parts[0]), trim($parts[1])];
            }
        }

        $parts = preg_split('/\s+/', $line, 2);

        if (count($parts) === 2) {
            return [trim($parts[0]), trim($parts[1])];
        }

        return [trim($parts[0]), null];
    }

    private function isValidQuantity(string $qty): bool
    {
        if (! ctype_digit($qty)) {
            return false;
        }

        return (int) $qty > 0;
    }
}

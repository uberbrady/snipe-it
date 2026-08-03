<?php

namespace Tests\Unit\Importer;

use App\Importer\AssetImporter;
use ReflectionClass;
use Tests\TestCase;

/**
 * Regression tests for the CSV encoding-detection layer in Importer::__construct
 * and Importer::findCsvMatch. Motivated by PR #19418 (mojibake on non-UTF-8
 * imports, especially CJK CSVs from Excel exports on Windows).
 *
 * The importer accepts either a path or a raw string. Both paths must land at
 * a CSV reader whose rows contain UTF-8 bytes regardless of the source
 * encoding, and must not double-encode content that was already UTF-8.
 */
class ImporterEncodingTest extends TestCase
{
    private function csvRowsFromString(string $csv): array
    {
        $importer = new AssetImporter($csv);

        $reader = (new ReflectionClass($importer))
            ->getProperty('csv')
            ->getValue($importer);

        return iterator_to_array($reader->getRecords());
    }

    public function test_utf8_content_is_preserved_without_double_encoding(): void
    {
        // Chinese chars in UTF-8: 你好 = E4 BD A0 E5 A5 BD
        $utf8Csv = "name,note\n你好,greeting\n";

        $rows = $this->csvRowsFromString($utf8Csv);

        $this->assertCount(2, $rows);
        $this->assertSame(['name', 'note'], $rows[0]);
        $this->assertSame('你好', $rows[1][0]);
    }

    public function test_non_utf8_input_becomes_valid_utf8_output(): void
    {
        // The purpose of the conversion layer is "whatever the source
        // encoding, downstream sees UTF-8". We deliberately don't assert
        // the exact converted characters here: short-string auto-detection
        // (Onnov + mb_detect_encoding fallback) is not deterministic across
        // encodings, and coupling the test to specific detector output
        // makes it brittle. What matters is the invariant: non-UTF-8 bytes
        // in, valid UTF-8 out.
        $windows1252Csv = "name,note\ncaf\xE9,drink\n";

        // Pre-condition: raw bytes are not valid UTF-8.
        $this->assertFalse(mb_check_encoding($windows1252Csv, 'UTF-8'));

        $rows = $this->csvRowsFromString($windows1252Csv);

        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            foreach ($row as $cell) {
                $this->assertTrue(mb_check_encoding($cell, 'UTF-8'), "Cell not UTF-8: {$cell}");
            }
        }
    }

    public function test_gbk_input_becomes_valid_utf8_output(): void
    {
        // Same invariant test with GBK-encoded bytes. Pad the content so
        // detectors have enough signal to lean toward CJK rather than
        // shorter-string ambiguity. Repeat the "你好" pattern several times
        // to give the detector a strong hint.
        $gbkGreeting = str_repeat("\xC4\xE3\xBA\xC3", 8);
        $gbkCsv = "name,note\n{$gbkGreeting},{$gbkGreeting}\n";

        $this->assertFalse(mb_check_encoding($gbkCsv, 'UTF-8'));

        $rows = $this->csvRowsFromString($gbkCsv);

        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            foreach ($row as $cell) {
                $this->assertTrue(mb_check_encoding($cell, 'UTF-8'), "Cell not UTF-8: {$cell}");
            }
        }
    }

    public function test_find_csv_match_leaves_valid_utf8_unchanged(): void
    {
        $importer = new AssetImporter("name\ntest\n");

        // "café" as valid UTF-8: 63 61 66 C3 A9
        $utf8Value = "caf\xC3\xA9";
        $row = ['name' => $utf8Value];

        $result = $importer->findCsvMatch($row, 'name');

        $this->assertSame('café', $result);
        $this->assertSame($utf8Value, $result);
    }

    public function test_find_csv_match_converts_non_utf8_value(): void
    {
        $importer = new AssetImporter("name\ntest\n");

        // "café" as Windows-1252: 63 61 66 E9 (not valid UTF-8 as a bare byte)
        $windows1252Value = "caf\xE9";
        $row = ['name' => $windows1252Value];

        $result = $importer->findCsvMatch($row, 'name');

        $this->assertTrue(mb_check_encoding($result, 'UTF-8'));
        $this->assertSame('café', $result);
    }

    public function test_ascii_only_pathstring_still_works(): void
    {
        // Regression: the existing test suite passes literal 'assets.csv' as
        // the constructor arg (not a real path). is_file() is false, so the
        // string is treated as CSV content. mb_check_encoding on plain ASCII
        // returns true, so the conversion block is skipped and the reader
        // parses the string as before.
        $importer = new AssetImporter('assets.csv');

        $reader = (new ReflectionClass($importer))
            ->getProperty('csv')
            ->getValue($importer);

        $this->assertNotNull($reader);
    }
}

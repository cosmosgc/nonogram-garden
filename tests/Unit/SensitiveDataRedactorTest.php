<?php

namespace Tests\Unit;

use App\Support\Logging\SensitiveDataRedactor;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class SensitiveDataRedactorTest extends TestCase
{
    private SensitiveDataRedactor $redactor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->redactor = new SensitiveDataRedactor;
    }

    public function test_sensitive_keys_are_redacted_regardless_of_spelling(): void
    {
        $clean = $this->redactor->scrubArray([
            'password' => 'hunter2',
            'Password_Confirmation' => 'hunter2',
            'rememberToken' => 'abc123',
            'email' => 'player@example.com',
            'nested' => [
                'solution_hash' => 'deadbeef',
                'marks' => '001100',
                'title' => 'Lighthouse',
            ],
        ]);

        $this->assertSame(SensitiveDataRedactor::PLACEHOLDER, $clean['password']);
        $this->assertSame(SensitiveDataRedactor::PLACEHOLDER, $clean['Password_Confirmation']);
        $this->assertSame(SensitiveDataRedactor::PLACEHOLDER, $clean['rememberToken']);
        $this->assertSame(SensitiveDataRedactor::PLACEHOLDER, $clean['email']);
        $this->assertSame(SensitiveDataRedactor::PLACEHOLDER, $clean['nested']['solution_hash']);
        $this->assertSame(SensitiveDataRedactor::PLACEHOLDER, $clean['nested']['marks']);
        $this->assertSame('Lighthouse', $clean['nested']['title']);
    }

    public function test_benign_values_survive_unchanged(): void
    {
        $clean = $this->redactor->scrubArray([
            'route' => 'puzzles.show',
            'status' => 404,
            'duration_ms' => 12.5,
            'cached' => false,
            'missing' => null,
            'width' => 20,
        ]);

        $this->assertSame('puzzles.show', $clean['route']);
        $this->assertSame(404, $clean['status']);
        $this->assertSame(12.5, $clean['duration_ms']);
        $this->assertFalse($clean['cached']);
        $this->assertNull($clean['missing']);
        $this->assertSame(20, $clean['width']);
    }

    public function test_free_text_is_scrubbed_for_answers_tokens_and_addresses(): void
    {
        $text = $this->redactor->scrubString(
            'Failed for player@example.com saving 1111000011110000 with Authorization: Bearer abcdef1234567890',
        );

        $this->assertStringNotContainsString('player@example.com', $text);
        $this->assertStringNotContainsString('1111000011110000', $text);
        $this->assertStringNotContainsString('abcdef1234567890', $text);
        $this->assertStringContainsString('[redacted:email]', $text);
        $this->assertStringContainsString('[redacted:cells]', $text);
    }

    public function test_data_urls_and_jwts_are_scrubbed(): void
    {
        $text = $this->redactor->scrubString(
            'body=data:image/png;base64,iVBORw0KGgoAAAANSUhEUg token=eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlFUP0THsR8U',
        );

        $this->assertStringNotContainsString('iVBORw0KGgoAAAANSUhEUg', $text);
        $this->assertStringNotContainsString('eyJhbGciOiJIUzI1NiJ9', $text);
        $this->assertStringContainsString('[redacted:data-url]', $text);
        $this->assertStringContainsString('[redacted:token]', $text);
    }

    public function test_short_binary_numbers_are_not_treated_as_cells(): void
    {
        $this->assertSame('status 1001 and 0101', $this->redactor->scrubString('status 1001 and 0101'));
    }

    public function test_long_strings_are_truncated_with_a_byte_count(): void
    {
        $text = $this->redactor->scrubString(str_repeat('a', SensitiveDataRedactor::MAX_STRING_LENGTH + 25));

        $this->assertStringContainsString('…[truncated 25 bytes]', $text);
        $this->assertSame(
            SensitiveDataRedactor::MAX_STRING_LENGTH + strlen('…[truncated 25 bytes]'),
            strlen($text),
        );
    }

    public function test_nested_arrays_are_bounded(): void
    {
        $deep = ['level' => 'value'];
        for ($i = 0; $i < SensitiveDataRedactor::MAX_DEPTH + 3; $i++) {
            $deep = ['child' => $deep];
        }

        $clean = $this->redactor->scrubArray($deep);
        $serialized = json_encode($clean);

        $this->assertIsString($serialized);
        $this->assertStringContainsString('[truncated:max-depth]', $serialized);

        $wide = [];
        for ($i = 0; $i < SensitiveDataRedactor::MAX_ITEMS + 10; $i++) {
            $wide['key'.$i] = $i;
        }

        $cleanWide = $this->redactor->scrubArray($wide);
        $this->assertCount(SensitiveDataRedactor::MAX_ITEMS + 1, $cleanWide);
        $this->assertSame('[truncated:max-items]', end($cleanWide));
    }

    public function test_objects_are_reduced_to_class_names(): void
    {
        $clean = $this->redactor->scrubArray([
            'exception' => new RuntimeException('secret details'),
            'at' => new DateTimeImmutable('2026-11-10T12:00:00+00:00'),
        ]);

        $this->assertSame(RuntimeException::class, $clean['exception']);
        $this->assertSame('2026-11-10T12:00:00+00:00', $clean['at']);
    }

    public function test_invalid_utf8_and_control_characters_are_made_loggable(): void
    {
        $clean = $this->redactor->scrubString("broken \xB1\x31 text\x00with\x07control");

        $this->assertTrue(mb_check_encoding($clean, 'UTF-8'));
        $this->assertStringNotContainsString("\x00", $clean);
        $this->assertStringContainsString('broken', $clean);
    }
}

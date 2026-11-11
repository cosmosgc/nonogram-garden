<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Monolog\Formatter\JsonFormatter;
use PDOException;
use RuntimeException;
use Tests\TestCase;

class StructuredErrorReportingTest extends TestCase
{
    use RefreshDatabase;

    private const CELLS = '1111000011110000';

    private string $logPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->logPath = storage_path('framework/testing/structured-errors-'.Str::uuid().'.log');
        File::ensureDirectoryExists(dirname($this->logPath));

        config([
            'logging.error_report.channel' => 'error-report-test',
            'logging.channels.error-report-test' => [
                'driver' => 'single',
                'path' => $this->logPath,
                'level' => 'debug',
                'formatter' => JsonFormatter::class,
                'formatter_with' => ['includeStacktraces' => false],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        File::delete($this->logPath);

        parent::tearDown();
    }

    public function test_server_errors_are_logged_as_structured_redacted_records(): void
    {
        $owner = User::factory()->create();
        Route::middleware('web')->post('/__tests/boom', function (): never {
            throw new RuntimeException('Save failed for player@example.com while storing '.self::CELLS);
        });

        $response = $this->actingAs($owner)->postJson('/__tests/boom', [
            'cells' => self::CELLS,
            'password' => 'hunter2-secret',
            'email' => 'player@example.com',
        ]);

        $response->assertStatus(500);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            (string) $response->headers->get('X-Request-Id'),
        );

        $log = File::get($this->logPath);
        $this->assertStringNotContainsString(self::CELLS, $log);
        $this->assertStringNotContainsString('hunter2-secret', $log);
        $this->assertStringNotContainsString('player@example.com', $log);

        $entry = $this->firstLogEntry();
        $record = $entry['context'];

        $this->assertSame('unhandled_exception', $entry['message']);
        $this->assertNotEmpty($entry['channel']);
        $this->assertSame('error', $record['severity']);
        $this->assertSame(RuntimeException::class, $record['exception']);
        $this->assertSame(500, $record['status']);
        $this->assertSame('POST', $record['method']);
        $this->assertSame('/__tests/boom', $record['path']);
        $this->assertSame($owner->id, $record['user_id']);
        $this->assertSame($response->headers->get('X-Request-Id'), $record['request_id']);
        $this->assertStringContainsString('[redacted:email]', $record['message']);
        $this->assertStringContainsString('[redacted:cells]', $record['message']);
        $this->assertStringNotContainsString(base_path(), $record['file']);
        $this->assertIsArray($record['trace']);
        $this->assertArrayHasKey('function', $record['trace'][0]);
        $this->assertArrayHasKey('file', $record['trace'][0]);
        $this->assertArrayHasKey('line', $record['trace'][0]);
    }

    public function test_trace_frames_omit_argument_values(): void
    {
        Route::middleware('web')->get('/__tests/arguments', fn () => $this->explodeWithSecret('secret-argument-value-123'));

        $this->get('/__tests/arguments')->assertStatus(500);

        $log = File::get($this->logPath);
        $this->assertStringNotContainsString('secret-argument-value-123', $log);
        $this->assertStringContainsString('explodeWithSecret', $log);
    }

    private function explodeWithSecret(string $secret): never
    {
        throw new RuntimeException('Argument probe');
    }

    public function test_database_failures_log_the_unbound_statement_only(): void
    {
        Route::middleware('web')->get('/__tests/database', function (): never {
            throw new QueryException(
                'mysql',
                'select * from puzzle_revisions where encoded_solution = ?',
                [self::CELLS],
                new PDOException('SQLSTATE[23000]: Integrity constraint violation'),
            );
        });

        $this->get('/__tests/database')->assertStatus(500);

        $log = File::get($this->logPath);
        $this->assertStringNotContainsString(self::CELLS, $log);

        $record = $this->firstLogEntry()['context'];
        $this->assertSame('Database query failed.', $record['message']);
        $this->assertSame('mysql', $record['database']['connection']);
        $this->assertSame('select * from puzzle_revisions where encoded_solution = ?', $record['database']['sql']);
        $this->assertSame(PDOException::class, $record['previous'][0]['exception']);
        $this->assertSame('SQLSTATE[23000]', $record['previous'][0]['message']);
    }

    public function test_client_errors_are_not_written_to_the_error_log(): void
    {
        Route::middleware('web')->get('/__tests/missing', fn () => abort(404));

        $response = $this->get('/__tests/missing');

        $response->assertNotFound();
        $this->assertFileDoesNotExist($this->logPath);
        $this->assertNotNull($response->headers->get('X-Request-Id'));
    }

    public function test_failed_dedicated_channel_uses_a_redacted_emergency_fallback(): void
    {
        config([
            'logging.channels.error-report-test' => ['driver' => 'unsupported-test-driver'],
            'logging.channels.emergency.path' => $this->logPath,
        ]);
        Route::middleware('web')->get('/__tests/log-fallback', function (): never {
            throw new RuntimeException('Could not save solution '.self::CELLS);
        });

        $this->get('/__tests/log-fallback')->assertStatus(500);

        $log = File::get($this->logPath);
        $this->assertStringNotContainsString(self::CELLS, $log);
        $this->assertStringContainsString('[redacted:cells]', $log);
        $this->assertStringContainsString('Unable to create configured logger. Using emergency logger.', $log);
    }

    public function test_request_ids_are_propagated_when_well_formed(): void
    {
        Route::middleware('web')->get('/__tests/ok', fn () => response('ok'));

        $this->get('/__tests/ok', ['X-Request-Id' => 'trace-abc-12345678'])
            ->assertOk()
            ->assertHeader('X-Request-Id', 'trace-abc-12345678');

        $generated = $this->get('/__tests/ok', ['X-Request-Id' => 'bad header value!'])
            ->assertOk()
            ->headers->get('X-Request-Id');

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            (string) $generated,
        );
    }

    public function test_error_records_never_include_the_request_body(): void
    {
        Route::middleware('web')->post('/__tests/validation-boom', function (Request $request): never {
            throw new RuntimeException('Unable to process payload of '.strlen((string) $request->getContent()).' bytes');
        });

        $this->postJson('/__tests/validation-boom', [
            'grid' => ['version' => 1, 'width' => 4, 'height' => 4, 'cells' => self::CELLS],
            'password_confirmation' => 'hunter2-secret',
        ])->assertStatus(500);

        $log = File::get($this->logPath);

        foreach ([self::CELLS, 'hunter2-secret', '"grid"', '"password_confirmation"'] as $canary) {
            $this->assertStringNotContainsString($canary, $log);
        }
    }

    /** @return array{message: string, channel: string, context: array<string, mixed>} */
    private function firstLogEntry(): array
    {
        $lines = array_values(array_filter(explode("\n", trim(File::get($this->logPath)))));
        $this->assertNotEmpty($lines, 'Expected a structured error record.');

        $decoded = json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Auth\Contracts\TokenRevocationRepository;
use App\Application\Auth\Contracts\TokenService;
use App\Application\Auth\Contracts\UserRepository;
use App\Application\Auth\Data\UserCredentialsData;
use App\Application\Auth\Data\UserData;
use App\Application\Imports\Contracts\ImportFileStorage;
use App\Application\Imports\Contracts\ImportRepository;
use App\Application\Imports\ImportUnavailable;
use App\Infrastructure\Storage\LocalImportFileStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Doubles\InMemoryImportRepository;
use Tests\Doubles\InMemoryTokenRevocationRepository;
use Tests\Doubles\InMemoryUserRepository;
use Tests\Support\TemporaryUploads;
use Tests\TestCase;

final class ImportHttpTest extends TestCase
{
    use TemporaryUploads;

    private InMemoryImportRepository $imports;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createUploadRoot();
        $this->imports = new InMemoryImportRepository;
        $this->app->instance(ImportRepository::class, $this->imports);
        $this->app->instance(ImportFileStorage::class, new LocalImportFileStorage($this->uploadRoot));
        $this->app->instance(UserRepository::class, new InMemoryUserRepository([new UserCredentialsData(new UserData('7', 'First', 'first@example.test'), 'unused'), new UserCredentialsData(new UserData('8', 'Second', 'second@example.test'), 'unused')]));
        $this->app->instance(TokenRevocationRepository::class, new InMemoryTokenRevocationRepository);
    }

    protected function tearDown(): void
    {
        $this->removeUploadRoot();
        parent::tearDown();
    }

    private function login(string $user = '7'): void
    {
        $this->withToken($this->app->make(TokenService::class)->issue($user)->accessToken);
    }

    private function file(string $name = 'transactions.csv'): UploadedFile
    {
        return new UploadedFile($this->sourceFile(), $name, 'text/plain', UPLOAD_ERR_OK, true);
    }

    private function upload(array $fields): TestResponse
    {
        return $this->post('/api/v1/imports', $fields, ['Content-Type' => 'multipart/form-data', 'Accept' => 'application/json']);
    }

    public function test_upload_returns_202_with_owned_status_url_and_no_financial_processing(): void
    {
        $this->login();
        $response = $this->upload(['file' => $this->file()])->assertStatus(202)->assertHeader('Location', '/api/v1/imports/1')
            ->assertJsonPath('data.status', 'pending')->assertJsonPath('data.processed_rows', '0')->assertJsonPath('data.prepared_at', null)
            ->assertJsonMissingPath('data.file_path')->assertJsonMissingPath('data.file_checksum')->assertJsonMissingPath('data.uploaded_by_user_id');
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->getJson('/api/v1/imports/1')->assertOk()->assertJsonPath('data.id', '1');
        $this->login('8');
        $foreign = $this->getJson('/api/v1/imports/1')->assertNotFound();
        self::assertSame($foreign->json(), $this->getJson('/api/v1/imports/999')->assertNotFound()->json());
    }

    public function test_unauthenticated_request_cannot_store_a_file(): void
    {
        $this->upload(['file' => $this->file()])->assertUnauthorized();
        self::assertSame([], $this->imports->imports);
        self::assertDirectoryDoesNotExist($this->uploadRoot.'/imports');
    }

    public function test_non_csv_and_multiple_or_nested_files_are_rejected(): void
    {
        $this->login();
        $this->upload(['file' => $this->file('archive.zip')])->assertStatus(422);
        $this->upload(['file' => $this->file(), 'other' => $this->file()])->assertStatus(422);
        $this->upload(['file' => [$this->file()]])->assertStatus(422);
        $this->upload(['file' => $this->file(), 'owner_user_id' => '8'])->assertStatus(422);
        self::assertSame([], $this->imports->imports);
    }

    #[DataProvider('uploadErrors')]
    public function test_php_upload_errors_are_mapped_without_storing_the_file(int $error, int $status): void
    {
        $this->login();
        $file = new UploadedFile('', 'a.csv', null, $error, true);
        $this->upload(['file' => $file])->assertStatus($status);
        self::assertSame([], $this->imports->imports);
    }

    public static function uploadErrors(): iterable
    {
        yield [UPLOAD_ERR_INI_SIZE, 413];
        yield [UPLOAD_ERR_FORM_SIZE, 413];
        yield [UPLOAD_ERR_PARTIAL, 422];
        yield [UPLOAD_ERR_NO_TMP_DIR, 503];
        yield [UPLOAD_ERR_CANT_WRITE, 503];
        yield [UPLOAD_ERR_EXTENSION, 503];
    }

    public function test_status_list_is_owner_scoped_and_capped_at_ten(): void
    {
        $this->login();
        for ($i = 0; $i < 12; $i++) {
            $this->upload(['file' => $this->file()])->assertStatus(202);
        }
        $this->getJson('/api/v1/imports?uploaded_by_user_id=8')->assertOk()->assertJsonCount(10, 'data')->assertJsonPath('meta.total', 12);
        $this->getJson('/api/v1/imports?page=2')->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/imports?per_page=11')->assertUnprocessable();
        $this->getJson('/api/v1/imports?page=9223372036854775807&per_page=10')->assertUnprocessable();
        $this->login('8');
        $this->getJson('/api/v1/imports')->assertJsonCount(0, 'data');
    }

    public function test_database_failure_returns_503_and_removes_only_confirmed_orphan(): void
    {
        $this->login();
        $repository = $this->createStub(ImportRepository::class);
        $repository->method('register')->willThrowException(new ImportUnavailable);
        $repository->method('referencesFile')->willReturn(false);
        $this->app->instance(ImportRepository::class, $repository);
        $this->upload(['file' => $this->file()])->assertStatus(503)->assertJsonMissingPath('data');
        self::assertSame([], glob($this->uploadRoot.'/imports/*'));
    }

    public function test_content_length_over_the_php_post_limit_is_json_413(): void
    {
        $this->login();
        $this->withServerVariables(['CONTENT_LENGTH' => '110000001'])->upload(['file' => $this->file()])->assertStatus(413)->assertJsonPath('code', 'upload_too_large');
    }
}

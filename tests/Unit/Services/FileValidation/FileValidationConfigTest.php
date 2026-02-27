<?php

declare(strict_types=1);

namespace Tests\Unit\Services\FileValidation;

use App\Services\FileValidation\FileTypeCategory;
use App\Services\FileValidation\FileValidationConfig;
use Illuminate\Config\Repository;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FileValidationConfigTest extends TestCase
{
    private FileValidationConfig $config;

    protected function setUp(): void
    {
        parent::setUp();
        $this->config = app(FileValidationConfig::class);
    }

    #[Test]
    public function it_returns_allowed_extensions_for_task_proof_preset(): void
    {
        $extensions = $this->config->getAllowedExtensions('task_proof');

        $this->assertIsArray($extensions);
        $this->assertContains('jpg', $extensions);
        $this->assertContains('pdf', $extensions);
        $this->assertContains('mp4', $extensions);
        $this->assertContains('zip', $extensions);
        // Новые OpenDocument форматы
        $this->assertContains('odt', $extensions);
        $this->assertContains('ods', $extensions);
        $this->assertContains('odp', $extensions);
        $this->assertContains('odf', $extensions);
        $this->assertContains('odg', $extensions);
    }

    #[Test]
    public function it_returns_only_image_extensions_for_shift_photo_preset(): void
    {
        $extensions = $this->config->getAllowedExtensions('shift_photo');

        $this->assertIsArray($extensions);
        $this->assertContains('jpg', $extensions);
        $this->assertContains('png', $extensions);
        $this->assertNotContains('pdf', $extensions);
        $this->assertNotContains('mp4', $extensions);
    }

    #[Test]
    public function it_throws_exception_for_unknown_preset(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Неизвестный пресет');

        $this->config->getAllowedExtensions('unknown_preset');
    }

    #[Test]
    public function it_returns_allowed_mime_types_for_task_proof(): void
    {
        $mimeTypes = $this->config->getAllowedMimeTypes('task_proof');

        $this->assertIsArray($mimeTypes);
        $this->assertContains('image/jpeg', $mimeTypes);
        $this->assertContains('application/pdf', $mimeTypes);
        $this->assertContains('video/mp4', $mimeTypes);
        // OpenDocument MIME types
        $this->assertContains('application/vnd.oasis.opendocument.text', $mimeTypes);
        $this->assertContains('application/vnd.oasis.opendocument.spreadsheet', $mimeTypes);
        $this->assertContains('application/vnd.oasis.opendocument.presentation', $mimeTypes);
        $this->assertContains('application/vnd.oasis.opendocument.formula', $mimeTypes);
        $this->assertContains('application/vnd.oasis.opendocument.graphics', $mimeTypes);
    }

    #[Test]
    public function it_returns_correct_max_size_for_categories(): void
    {
        $imageMaxSize = $this->config->getMaxSize(FileTypeCategory::IMAGE);
        $documentMaxSize = $this->config->getMaxSize(FileTypeCategory::DOCUMENT);
        $videoMaxSize = $this->config->getMaxSize(FileTypeCategory::VIDEO);

        $this->assertEquals(5 * 1024 * 1024, $imageMaxSize); // 5 MB
        $this->assertEquals(50 * 1024 * 1024, $documentMaxSize); // 50 MB
        $this->assertEquals(100 * 1024 * 1024, $videoMaxSize); // 100 MB
    }

    #[Test]
    public function it_returns_correct_limits(): void
    {
        $limits = $this->config->getLimits();

        $this->assertArrayHasKey('max_files_per_response', $limits);
        $this->assertArrayHasKey('max_total_size', $limits);
        $this->assertEquals(5, $limits['max_files_per_response']);
        $this->assertEquals(200 * 1024 * 1024, $limits['max_total_size']);
    }

    #[Test]
    public function it_returns_extension_to_mime_map(): void
    {
        $map = $this->config->getExtensionToMimeMap();

        $this->assertIsArray($map);
        $this->assertArrayHasKey('docx', $map);
        $this->assertArrayHasKey('xlsx', $map);
        $this->assertArrayHasKey('odt', $map);
        $this->assertArrayHasKey('ods', $map);
        $this->assertArrayHasKey('odf', $map);
    }

    #[Test]
    public function it_converts_config_to_array_for_api(): void
    {
        $array = $this->config->toArray('task_proof');

        $this->assertArrayHasKey('extensions', $array);
        $this->assertArrayHasKey('mime_types', $array);
        $this->assertArrayHasKey('limits', $array);
        $this->assertArrayHasKey('max_files', $array['limits']);
        $this->assertArrayHasKey('max_total_size', $array['limits']);
        $this->assertArrayHasKey('max_size_image', $array['limits']);
        $this->assertArrayHasKey('max_size_document', $array['limits']);
        $this->assertArrayHasKey('max_size_video', $array['limits']);
    }

    #[Test]
    public function it_checks_preset_existence(): void
    {
        $this->assertTrue($this->config->presetExists('task_proof'));
        $this->assertTrue($this->config->presetExists('shift_photo'));
        $this->assertFalse($this->config->presetExists('nonexistent'));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Services\FileValidation;

use App\Contracts\FileValidatorInterface;
use App\Services\FileValidation\FileTypeCategory;
use App\Services\FileValidation\FileValidator;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FileValidatorTest extends TestCase
{
    private FileValidatorInterface $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = app(FileValidatorInterface::class);
    }

    #[Test]
    public function it_validates_allowed_image_file(): void
    {
        $file = UploadedFile::fake()->image('test.jpg', 100, 100);

        // Не должно выбрасывать исключение
        $this->validator->validate($file, 'task_proof');
        $this->assertTrue(true);
    }

    #[Test]
    public function it_validates_allowed_pdf_file(): void
    {
        // Создаём файл с правильным PDF header
        $content = '%PDF-1.4 test content';
        $file = UploadedFile::fake()->createWithContent('test.pdf', $content);

        $this->validator->validate($file, 'task_proof');
        $this->assertTrue(true);
    }

    #[Test]
    public function it_rejects_disallowed_extension(): void
    {
        $file = UploadedFile::fake()->create('test.exe', 100, 'application/x-msdownload');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Недопустимое расширение файла');

        $this->validator->validate($file, 'task_proof');
    }

    #[Test]
    public function it_rejects_video_for_shift_photo_preset(): void
    {
        $file = UploadedFile::fake()->create('test.mp4', 100, 'video/mp4');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Недопустимое расширение файла');

        $this->validator->validate($file, 'shift_photo');
    }

    #[Test]
    public function it_rejects_file_exceeding_size_limit(): void
    {
        // Создаём изображение больше 5 MB
        $file = UploadedFile::fake()->image('test.jpg')->size(6000); // 6 MB

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Файл слишком большой');

        $this->validator->validate($file, 'task_proof');
    }

    #[Test]
    public function it_checks_allowed_extension(): void
    {
        $this->assertTrue($this->validator->isAllowedExtension('jpg', 'task_proof'));
        $this->assertTrue($this->validator->isAllowedExtension('pdf', 'task_proof'));
        $this->assertTrue($this->validator->isAllowedExtension('odf', 'task_proof'));
        $this->assertFalse($this->validator->isAllowedExtension('exe', 'task_proof'));
        $this->assertFalse($this->validator->isAllowedExtension('php', 'task_proof'));
    }

    #[Test]
    public function it_checks_allowed_mime_type(): void
    {
        $this->assertTrue($this->validator->isAllowedMimeType('image/jpeg', 'task_proof'));
        $this->assertTrue($this->validator->isAllowedMimeType('application/pdf', 'task_proof'));
        $this->assertTrue($this->validator->isAllowedMimeType('application/vnd.oasis.opendocument.formula', 'task_proof'));
        $this->assertFalse($this->validator->isAllowedMimeType('application/x-php', 'task_proof'));
    }

    #[Test]
    public function it_returns_correct_max_size_for_mime_type(): void
    {
        $imageMaxSize = $this->validator->getMaxSizeForMimeType('image/jpeg');
        $videoMaxSize = $this->validator->getMaxSizeForMimeType('video/mp4');
        $documentMaxSize = $this->validator->getMaxSizeForMimeType('application/pdf');

        $this->assertEquals(5 * 1024 * 1024, $imageMaxSize);
        $this->assertEquals(100 * 1024 * 1024, $videoMaxSize);
        $this->assertEquals(50 * 1024 * 1024, $documentMaxSize);
    }

    #[Test]
    public function it_returns_correct_category_for_mime_type(): void
    {
        $this->assertEquals(FileTypeCategory::IMAGE, $this->validator->getCategoryForMimeType('image/jpeg'));
        $this->assertEquals(FileTypeCategory::VIDEO, $this->validator->getCategoryForMimeType('video/mp4'));
        $this->assertEquals(FileTypeCategory::DOCUMENT, $this->validator->getCategoryForMimeType('application/pdf'));
        $this->assertEquals(FileTypeCategory::ARCHIVE, $this->validator->getCategoryForMimeType('application/zip'));
    }

    #[Test]
    public function it_returns_allowed_extensions_list(): void
    {
        $extensions = $this->validator->getAllowedExtensions('task_proof');

        $this->assertIsArray($extensions);
        $this->assertContains('jpg', $extensions);
        $this->assertContains('odf', $extensions);
    }

    #[Test]
    public function it_returns_allowed_mime_types_list(): void
    {
        $mimeTypes = $this->validator->getAllowedMimeTypes('task_proof');

        $this->assertIsArray($mimeTypes);
        $this->assertContains('image/jpeg', $mimeTypes);
        $this->assertContains('application/vnd.oasis.opendocument.formula', $mimeTypes);
    }

    #[Test]
    public function it_validates_mime_type_directly(): void
    {
        // Не должно выбрасывать исключение
        $this->validator->validateMimeType('image/jpeg', 'task_proof');
        $this->validator->validateMimeType('application/pdf', 'task_proof');
        $this->assertTrue(true);
    }

    #[Test]
    public function it_rejects_invalid_mime_type_directly(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Недопустимый тип файла');

        $this->validator->validateMimeType('application/x-php', 'task_proof');
    }

    #[Test]
    public function it_resolves_mime_type_for_uploaded_file(): void
    {
        $file = UploadedFile::fake()->image('test.jpg');
        $mimeType = $this->validator->resolveMimeType($file);

        $this->assertEquals('image/jpeg', $mimeType);
    }
}

<?php

namespace Tests\Feature\Auth;

use App\Http\Requests\Logistics\StoreLogisticsRegistrationRequest;
use App\Http\Requests\Logistics\StoreRiderRequest;
use App\Http\Requests\Rider\StoreRiderRegistrationRequest;
use App\Http\Requests\Seller\StoreSellerRegistrationRequest;
use App\Support\DocumentUpload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Shared document upload rule: TC-SEL-029..031, TC-LOG-029..031, TC-RID-016..018 (audit E-11).
 */
class DocumentUploadRuleTest extends TestCase
{
    private const PNG = "\x89PNG\r\n\x1A\n\x00\x00\x00\x0DIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1F\x15\xC4\x89\x00\x00\x00\x0DIDATx\x9Cc\xF8\x0F\x00\x00\x01\x01\x00\x05\x18\xD8N\x00\x00\x00\x00IEND\xAEB`\x82";

    /** A real file on disk, so the type is detected from its content, not its name. */
    private function realFile(string $name, string $content, int $padToBytes = 0): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'doc');
        file_put_contents($path, $content.str_repeat("\0", max(0, $padToBytes - strlen($content))));

        return new UploadedFile($path, $name, null, null, true);
    }

    private function fails(UploadedFile $file): bool
    {
        return Validator::make(['doc' => $file], ['doc' => DocumentUpload::rules()])->fails();
    }

    public function test_jpg_png_and_pdf_are_accepted(): void
    {
        $this->assertFalse($this->fails($this->realFile('govt_id_front.png', self::PNG)));
        $this->assertFalse($this->fails($this->realFile('dti_certificate.pdf', "%PDF-1.4\n%%EOF\n")));
        $this->assertFalse($this->fails(UploadedFile::fake()->create('govt_id_front.jpg', 100, 'image/jpeg')));
    }

    public function test_exactly_5120_kb_is_accepted_and_one_more_kilobyte_is_not(): void
    {
        $this->assertFalse($this->fails($this->realFile('size_5.0MB.pdf', "%PDF-1.4\n", 5120 * 1024)));
        $this->assertTrue($this->fails($this->realFile('size_5.1MB.pdf', "%PDF-1.4\n", 5121 * 1024)));
    }

    /** @return array<string, array{string, string}> */
    public static function rejectedFiles(): array
    {
        return [
            'docx' => ['price_list.docx', "PK\x03\x04".str_repeat('x', 64)],
            'gif' => ['store_banner.gif', 'GIF89a'.str_repeat("\0", 32)],
            'exe' => ['installer.exe', 'MZ'.str_repeat("\0", 64)],
            'exe renamed to .jpg' => ['renamed_exe.jpg', 'MZ'.str_repeat("\0", 64)],
            'png renamed to .exe' => ['photo.exe', self::PNG],
        ];
    }

    #[DataProvider('rejectedFiles')]
    public function test_other_types_are_rejected_by_content_and_extension(string $name, string $content): void
    {
        $this->assertTrue($this->fails($this->realFile($name, $content)), $name);
    }

    public function test_message_names_the_types_and_the_limit(): void
    {
        $validator = Validator::make(
            ['doc' => $this->realFile('store_banner.gif', 'GIF89a')],
            ['doc' => DocumentUpload::rules()],
            DocumentUpload::messages('doc', 'Government-issued ID'),
        );

        $this->assertSame('Government-issued ID must be a JPG, PNG or PDF file no larger than 5 MB.', $validator->errors()->first('doc'));
    }

    public function test_every_registration_document_uses_the_shared_rule(): void
    {
        $fields = [
            StoreSellerRegistrationRequest::class => ['upload_id', 'business_permit'],
            StoreLogisticsRegistrationRequest::class => ['upload_id', 'dti_permit_upload'],
            StoreRiderRequest::class => ['drivers_license', 'or_cr'],
            StoreRiderRegistrationRequest::class => ['drivers_license', 'or_cr'],
        ];

        foreach ($fields as $request => $names) {
            $rules = (new $request)->rules();
            foreach ($names as $name) {
                $this->assertSame(DocumentUpload::rules(), $rules[$name], $request.' '.$name);
            }
        }
    }
}

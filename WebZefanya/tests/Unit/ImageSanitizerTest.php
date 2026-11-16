<?php

namespace Tests\Unit;

use App\Support\ImageSanitizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ImageSanitizerTest extends TestCase
{
    /** A JPEG carrying an EXIF block with GPS-style text and orientation 6 (rotate 90 CW). */
    private function jpegWithExif(int $width, int $height, int $orientation = 6): UploadedFile
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagejpeg($image);
        $jpeg = (string) ob_get_clean();

        $tiff = "II*\0\x08\0\0\0"."\x02\0"
            .pack('vvVv', 0x0112, 3, 1, $orientation)."\0\0"
            .pack('vvVV', 0x010F, 2, 8, 38)
            ."\0\0\0\0"."SECRETGPS\0";
        $app1 = "Exif\0\0".$tiff;
        $payload = substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($app1) + 2).$app1.substr($jpeg, 2);
        $path = tempnam(sys_get_temp_dir(), 'exif').'.jpg';
        file_put_contents($path, $payload);

        return new UploadedFile($path, 'photo.jpg', 'image/jpeg', null, true);
    }

    public function test_reencoding_drops_exif_and_applies_the_rotation(): void
    {
        Storage::fake('public');
        $file = $this->jpegWithExif(40, 20);
        $this->assertStringContainsString('SECRETGPS', file_get_contents($file->getPathname()));
        $this->assertSame(6, ImageSanitizer::jpegOrientation($file->getPathname()));

        $path = ImageSanitizer::store($file, 'products/1');

        $stored = Storage::disk('public')->get($path);
        $this->assertStringNotContainsString('SECRETGPS', $stored);
        $this->assertStringNotContainsString('Exif', $stored);
        $size = getimagesizefromstring($stored);
        $this->assertSame([20, 40], [$size[0], $size[1]], 'orientation 6 swaps width and height');
        $this->assertMatchesRegularExpression('#^products/1/[A-Za-z0-9]{40}\.jpg$#', $path);
    }

    public function test_oversized_or_non_image_files_are_rejected(): void
    {
        Storage::fake('public');
        config(['uploads.max_side_px' => 100]);

        $this->expectException(ValidationException::class);
        ImageSanitizer::store(UploadedFile::fake()->image('big.jpg', 101, 50), 'products/1');
    }

    public function test_fake_images_pass_and_files_that_only_pretend_are_refused(): void
    {
        Storage::fake('public');
        $this->assertNotEmpty(ImageSanitizer::store(UploadedFile::fake()->image('ok.png', 50, 50), 'x'));

        $this->expectException(ValidationException::class);
        ImageSanitizer::store(UploadedFile::fake()->createWithContent('evil.jpg', '<?php echo 1;'), 'x');
    }

    public function test_dimension_rules_cap_side_and_total_pixels(): void
    {
        config(['uploads.max_side_px' => 100, 'uploads.max_pixels' => 2000]);
        $rules = ['photo' => ['file', 'image', 'mimes:jpg,jpeg,png', ...\App\Support\ImageUpload::dimensionRules()]];

        $this->assertTrue(Validator::make(['photo' => UploadedFile::fake()->image('a.jpg', 40, 40)], $rules)->passes());
        $this->assertTrue(Validator::make(['photo' => UploadedFile::fake()->image('a.jpg', 101, 10)], $rules)->fails());
        $this->assertTrue(Validator::make(['photo' => UploadedFile::fake()->image('a.jpg', 60, 60)], $rules)->fails());
    }
}

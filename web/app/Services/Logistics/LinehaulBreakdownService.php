<?php

namespace App\Services\Logistics;

use App\Models\Logistics\LinehaulBreakdown;
use App\Models\Logistics\LinehaulDriver;
use App\Models\Logistics\LinehaulTripDelay;
use App\Models\Logistics\Manifest;
use App\Models\Logistics\ManifestAuditLog;
use App\Policies\LinehaulPolicy;
use App\Support\RuntimeSchema;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LinehaulBreakdownService
{
    public static function installed(): bool
    {
        return LinehaulDelayService::installed() && RuntimeSchema::hasTable('linehaul_breakdown_reports');
    }

    public static function rules(): array
    {
        $rules = LinehaulDelayService::rules();
        $rules['reason'] = ['required', 'string', 'max:480'];
        $rules['photo'] = ['required', 'file', 'image', 'mimes:jpg,jpeg,png', 'max:5120', 'dimensions:max_width=4096,max_height=4096'];
        foreach (['photo_path', 'photo_hash', 'linehaul_trip_delay_id', 'type'] as $field) {
            $rules[$field] = ['prohibited'];
        }

        return $rules;
    }

    public function report(LinehaulDriver $driver, Manifest $manifest, array $input, Request $request): LinehaulBreakdown
    {
        abort_unless((new LinehaulPolicy)->access($driver, $manifest), 403);
        $this->require(self::installed(), 'Courier breakdown setup is pending. Contact Logistics.');
        $data = Validator::make($input, self::rules())->validate();
        $this->require(trim($data['reason']) !== '', 'Describe the vehicle breakdown.');
        $reason = 'Vehicle breakdown: '.trim($data['reason']);
        $eta = Carbon::parse($data['new_eta'])->utc()->startOfSecond();
        $photoHash = hash_file('sha256', $data['photo']->getRealPath());
        $newPath = null;
        try {
            return DB::transaction(function () use ($driver, $manifest, $data, $request, $reason, $eta, $photoHash, &$newPath): LinehaulBreakdown {
                $assignment = app(LinehaulDelayService::class)->report($driver, $manifest,
                    ['reason' => $reason, 'new_eta' => $data['new_eta'], 'offer_version' => $data['offer_version']], $request);
                $delay = LinehaulTripDelay::where('linehaul_assignment_id', $assignment->id)
                    ->where('linehaul_driver_id', $driver->id)->where('logistics_provider_id', $driver->logistics_provider_id)
                    ->where('offer_version', $data['offer_version'])->where('reason', $reason)
                    ->where('new_eta', $eta->toDateTimeString())->firstOrFail();
                $existing = LinehaulBreakdown::where('linehaul_trip_delay_id', $delay->id)->first();
                if ($existing !== null) {
                    $this->require(hash_equals($existing->photo_hash, $photoHash), 'This report already has different photo evidence. Submit a new report instead.');

                    return $existing->load('delay');
                }
                $assignment->setRelation('manifest', $manifest->fresh());
                $this->require(LinehaulDelayService::canReport($assignment) && $eta->isFuture(), 'Submit breakdown evidence only In Transit, before arrival, with a future ETA.');
                $jpeg = $this->normalizePhoto($data['photo']);
                $newPath = 'courier-breakdowns/'.Str::uuid().'.jpg';
                abort_unless(Storage::disk('local')->put($newPath, $jpeg), 503, 'Could not store breakdown evidence. Please retry.');
                $report = new LinehaulBreakdown;
                $report->forceFill(['linehaul_trip_delay_id' => $delay->id, 'photo_path' => $newPath, 'photo_hash' => $photoHash])->save();
                ManifestAuditLog::create(['manifest_id' => $manifest->id, 'action' => 'courier_breakdown', 'actor_type' => 'linehaul',
                    'actor_id' => $driver->id, 'actor_name' => $driver->name, 'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(), 'verification_method' => 'photo']);

                return $report->load('delay');
            });
        } catch (\Throwable $error) {
            if ($newPath !== null) {
                Storage::disk('local')->delete($newPath);
            }
            throw $error;
        }
    }

    private function normalizePhoto(UploadedFile $photo): string
    {
        abort_unless(function_exists('imagecreatefromstring'), 503, 'Photo processing is unavailable. Contact Logistics.');
        $source = @imagecreatefromstring(file_get_contents($photo->getRealPath()));
        $this->require($source !== false, 'Select a readable JPG or PNG photo.');
        $scale = min(1, 1280 / max(imagesx($source), imagesy($source)));
        $width = max(1, (int) round(imagesx($source) * $scale));
        $height = max(1, (int) round(imagesy($source) * $scale));
        $target = imagecreatetruecolor($width, $height);
        $stream = fopen('php://memory', 'r+');
        try {
            imagefill($target, 0, 0, imagecolorallocate($target, 255, 255, 255));
            imagecopyresampled($target, $source, 0, 0, 0, 0, $width, $height, imagesx($source), imagesy($source));
            abort_unless(imagejpeg($target, $stream, 80), 503, 'Could not process breakdown evidence.');
            rewind($stream);

            return stream_get_contents($stream);
        } finally {
            imagedestroy($target);
            imagedestroy($source);
            fclose($stream);
        }
    }

    private function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['trip' => $message]);
        }
    }
}

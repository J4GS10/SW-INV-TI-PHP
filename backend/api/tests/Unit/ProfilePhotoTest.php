<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\ProfilePhoto\Domain\ProfilePhoto;
use PHPUnit\Framework\TestCase;

final class ProfilePhotoTest extends TestCase
{
    public function testAnyPictureBecomesASquareWebpWithoutItsMetadata(): void
    {
        $image = imagecreatetruecolor(800, 400);
        self::assertNotFalse($image);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 10, 120, 200));
        ob_start();
        imagejpeg($image);
        $jpeg = (string) ob_get_clean();

        $photo = ProfilePhoto::fromUpload($jpeg);

        self::assertNotNull($photo);
        $info = getimagesizefromstring($photo->bytes);
        self::assertNotFalse($info);
        self::assertSame([256, 256, ProfilePhoto::MIME], [$info[0], $info[1], $info['mime']]);
    }

    public function testSomethingThatIsNotAPictureIsRejected(): void
    {
        self::assertNull(ProfilePhoto::fromUpload('<?php echo 1;'));
        self::assertNull(ProfilePhoto::fromUpload(''));
    }

    public function testTheAddressChangesWithEveryNewPhotoAndIsNullWithoutOne(): void
    {
        self::assertNull(ProfilePhoto::url('/api/v1/me/foto', null));
        $first = ProfilePhoto::url('/api/v1/me/foto', '2026-11-10 10:00:00.000000');
        self::assertStringStartsWith('/api/v1/me/foto?v=', (string) $first);
        self::assertNotSame($first, ProfilePhoto::url('/api/v1/me/foto', '2026-11-10 10:00:01.000000'));
    }
}

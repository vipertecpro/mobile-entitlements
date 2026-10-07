<?php

namespace Vipertecpro\MobileEntitlements\Tests\Support;

use Carbon\CarbonImmutable;

/**
 * Loads JSON fixtures. Time placeholders keep them valid whenever the suite runs:
 * "@now", "@now+30d", "@now-1h" become epoch milliseconds (int); add ":iso" for an RFC 3339 string
 * or ":str" for milliseconds as a string (Google's eventTimeMillis).
 */
final class Fixtures
{
    public static function path(string $relative): string
    {
        return dirname(__DIR__).'/fixtures/'.$relative;
    }

    /**
     * @return array<string, mixed>
     */
    public static function json(string $relative): array
    {
        $decoded = json_decode((string) file_get_contents(self::path($relative)), true, 512, JSON_THROW_ON_ERROR);

        return self::resolve($decoded);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function resolve(array $data): array
    {
        array_walk_recursive($data, function (mixed &$value): void {
            if (is_string($value) && preg_match('/^@now(?:([+-])(\d+)([smhd]))?(?::(iso|str))?$/', $value, $m) === 1) {
                $time = CarbonImmutable::now();

                if (($m[1] ?? '') !== '') {
                    $seconds = (int) $m[2] * ['s' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400][$m[3]];
                    $time = $m[1] === '+' ? $time->addSeconds($seconds) : $time->subSeconds($seconds);
                }

                $value = match ($m[4] ?? '') {
                    'iso' => $time->utc()->format('Y-m-d\TH:i:s.v\Z'),
                    'str' => (string) $time->getTimestampMs(),
                    default => $time->getTimestampMs(),
                };
            }
        });

        return $data;
    }

    /**
     * A signedPayload for tests/fixtures/apple/notifications/{name}.json. The fixture holds the
     * decoded transactionInfo / renewalInfo; they are signed into signedTransactionInfo /
     * signedRenewalInfo, then the whole notification is signed.
     *
     * @param  array<string, mixed>  $signOptions
     */
    public static function appleNotification(string $name, array $signOptions = [], array $overrides = []): string
    {
        $notification = array_replace_recursive(self::json("apple/notifications/{$name}.json"), $overrides);

        return AppleSigner::sign(self::signAppleData($notification, $signOptions), $signOptions);
    }

    /**
     * @param  array<string, mixed>  $notification
     * @param  array<string, mixed>  $signOptions
     * @return array<string, mixed>
     */
    public static function signAppleData(array $notification, array $signOptions = []): array
    {
        if (isset($notification['data']['transactionInfo'])) {
            $notification['data']['signedTransactionInfo'] = AppleSigner::sign($notification['data']['transactionInfo'], $signOptions);
            unset($notification['data']['transactionInfo']);
        }

        if (isset($notification['data']['renewalInfo'])) {
            $notification['data']['signedRenewalInfo'] = AppleSigner::sign($notification['data']['renewalInfo'], $signOptions);
            unset($notification['data']['renewalInfo']);
        }

        return $notification;
    }

    /**
     * Pub/Sub push body for tests/fixtures/google/notifications/{name}.json.
     *
     * @return array<string, mixed>
     */
    public static function googlePush(string $name, ?string $messageId = null): array
    {
        $notification = self::json("google/notifications/{$name}.json");

        return [
            'message' => [
                'attributes' => [],
                'data' => base64_encode((string) json_encode($notification)),
                'messageId' => $messageId ?? 'msg-'.$name,
                'publishTime' => CarbonImmutable::now()->utc()->format('Y-m-d\TH:i:s.v\Z'),
            ],
            'subscription' => 'projects/example-project/subscriptions/play-rtdn-push',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function play(string $name): array
    {
        return self::json("google/play/{$name}.json");
    }
}

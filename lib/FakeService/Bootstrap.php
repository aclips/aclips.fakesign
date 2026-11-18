<?php

declare(strict_types=1);

namespace Aclips\Fakesign\FakeService;

use Aclips\Fakesign\FakeService\Handler\CompanyHandler;
use Aclips\Fakesign\FakeService\Handler\DocumentHandler;
use Aclips\Fakesign\FakeService\Handler\FileHandler;
use Aclips\Fakesign\FakeService\Handler\MemberHandler;
use Aclips\Fakesign\FakeService\Handler\ProviderHandler;

/**
 * Регистрация хендлеров фейк-сервиса в Api-роутере (идемпотентно).
 * Вызывается из public/index.php перед обработкой и из тестов.
 */
final class Bootstrap
{
    private static bool $registered = false;

    /**
     * Регистрирует все обработчики фейк-сервиса.
     */
    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        Api::register('v1/b2e.company.get', [CompanyHandler::class, 'companyGet']);
        Api::register('v1/b2e.company.provider.get', [CompanyHandler::class, 'providerCode']);
        Api::register('v1/b2e.company.registerByClient', [CompanyHandler::class, 'registerByClient']);
        Api::register('v1/b2e.company.delete', [CompanyHandler::class, 'companyDelete']);
        Api::register('v1/b2e.provider.getAvailableSchemes', [ProviderHandler::class, 'schemes']);
        Api::register('v1/b2e.provider.getFields', [ProviderHandler::class, 'fields']);
        Api::register('v1/b2e.external-sign-provider.list', [ProviderHandler::class, 'list']);
        Api::register('v1/b2e.external-sign-provider.add', [ProviderHandler::class, 'add']);
        Api::register('v1/b2e.external-sign-provider.edit', [ProviderHandler::class, 'edit']);
        Api::register('v1/b2e.external-sign-provider.delete', [ProviderHandler::class, 'delete']);

        Api::register('v1/document.register', [DocumentHandler::class, 'register']);
        Api::register('v1/document.signing.configure', [DocumentHandler::class, 'configure']);
        Api::register('v1/document.page.list', [DocumentHandler::class, 'pages']);
        Api::register('v1/document.placeholder.list', [DocumentHandler::class, 'placeholders']);
        Api::register('v1/document.signing.stop.batch', [DocumentHandler::class, 'stopBatch']);

        Api::register('v1/document.upload', [DocumentHandler::class, 'upload']);
        Api::register('v1/document.page.view', [DocumentHandler::class, 'pageView']);

        Api::register('v1/document.signing.start', [DocumentHandler::class, 'start']);

        foreach ([
                     'v1/document.reuse',
                     'v1/document.signUntil.update',
                     'v1/document.field.fill',
                     'v1/document.signing.stop',
                     'v1/document.signing.sendInvite',
                     'v1/document.signing.resendMessage',
                 ] as $acceptPath) {
            Api::register($acceptPath, [DocumentHandler::class, 'accept']);
        }
        Api::register('v1/b2e.member.getlinkforsigning', [MemberHandler::class, 'linkForSigning']);
        Api::register('v1/document.member.status.get', [MemberHandler::class, 'statusGet']);
        Api::register('v1/b2e.document.signing.refuse.batch', [MemberHandler::class, 'refuseBatch']);
        Api::register('v1/b2e.document.reviewer.accept.batch', [MemberHandler::class, 'reviewAcceptBatch']);
        Api::register('v1/b2e.document.signing.sign', [MemberHandler::class, 'sign']);
        Api::register('v1/b2e.document.signing.refuse', [MemberHandler::class, 'refuse']);
        Api::register('v1/b2e.document.reviewer.accept', [MemberHandler::class, 'reviewAccept']);

        Api::register('v1/document.signedfile.load', [FileHandler::class, 'signedFile']);
        Api::register('v1/document.printversion.load', [FileHandler::class, 'printVersion']);
    }
}

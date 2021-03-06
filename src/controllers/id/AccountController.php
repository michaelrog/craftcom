<?php

namespace craftnet\controllers\id;

use Craft;
use craft\commerce\Plugin as Commerce;
use craft\elements\Asset;
use craft\errors\UploadFailedException;
use craft\helpers\Assets;
use craft\helpers\FileHelper;
use craft\web\Controller;
use craft\web\UploadedFile;
use craftnet\Module;
use Throwable;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Class AccountController
 */
class AccountController extends Controller
{
    // Public Methods
    // =========================================================================

    /**
     * Account index.
     *
     * @return Response
     */
    public function actionIndex(): Response
    {
        $stripeAccessToken = null;
        $user = Craft::$app->getUser()->getIdentity();

        $craftIdConfig = Craft::$app->getConfig()->getConfigFromFile('craftid');
        $stripePublicKey = $craftIdConfig['stripePublicKey'];

        return $this->renderTemplate('account/index', [
            'stripeAccessToken' => $user->stripeAccessToken,
            'stripePublicKey' => $stripePublicKey
        ]);
    }

    /**
     * Upload a user photo.
     *
     * @return null|Response
     * @throws BadRequestHttpException
     * @throws \yii\web\ForbiddenHttpException
     */
    public function actionUploadUserPhoto()
    {
        $this->requireAcceptsJson();
        $this->requireLogin();

        if (($file = UploadedFile::getInstanceByName('photo')) === null) {
            return null;
        }

        try {
            if ($file->getHasError()) {
                throw new UploadFailedException($file->error);
            }

            $user = Craft::$app->getUser()->getIdentity();

            // Move to our own temp location
            $fileLocation = Assets::tempFilePath($file->getExtension());
            move_uploaded_file($file->tempName, $fileLocation);
            Craft::$app->getUsers()->saveUserPhoto($fileLocation, $user, $file->name);

            return $this->asJson([
                'photoId' => $user->photoId,
                'photoUrl' => $user->getThumbUrl(200),
            ]);
        } catch (\Throwable $exception) {
            /** @noinspection UnSafeIsSetOverArrayInspection - FP */
            if (isset($fileLocation)) {
                FileHelper::removeFile($fileLocation);
            }

            Craft::error('There was an error uploading the photo: '.$exception->getMessage(), __METHOD__);

            return $this->asErrorJson(Craft::t('app', 'There was an error uploading your photo: {error}', [
                'error' => $exception->getMessage()
            ]));
        }
    }

    /**
     * Delete all the photos for current user.
     *
     * @return Response
     * @throws BadRequestHttpException
     * @throws \Throwable
     * @throws \craft\errors\ElementNotFoundException
     * @throws \yii\base\Exception
     * @throws \yii\web\ForbiddenHttpException
     */
    public function actionDeleteUserPhoto(): Response
    {
        $this->requireAcceptsJson();
        $this->requireLogin();
        $user = Craft::$app->getUser()->getIdentity();

        if ($user->photoId) {
            Craft::$app->getElements()->deleteElementById($user->photoId, Asset::class);
        }

        $user->photoId = null;

        Craft::$app->getElements()->saveElement($user, false);

        return $this->asJson([
            'photoId' => $user->photoId,
            'photoUrl' => $user->getThumbUrl(200),
        ]);
    }

    /**
     * Generate API token.
     *
     * @return Response
     * @throws \yii\web\BadRequestHttpException
     */
    public function actionGenerateApiToken(): Response
    {
        $this->requirePostRequest();
        $this->requireLogin();

        $user = Craft::$app->getUser()->getIdentity();

        if (!$user->isInGroup('developers')) {
            throw new ForbiddenHttpException('User is not permitted to perform this action');
        }

        try {
            $apiToken = $user->generateApiToken();

            return $this->asJson(['apiToken' => $apiToken]);
        } catch (Throwable $e) {
            return $this->asErrorJson($e->getMessage());
        }
    }

    /**
     * Get invoices.
     *
     * @return Response
     */
    public function actionGetInvoices(): Response
    {
        $this->requireLogin();
        $user = Craft::$app->getUser()->getIdentity();

        try {
            $customer = Commerce::getInstance()->getCustomers()->getCustomerByUserId($user->id);

            $invoices = [];
            
            if ($customer) {
                $invoices = Module::getInstance()->getInvoiceManager()->getInvoices($customer);
            }

            return $this->asJson($invoices);
        } catch (Throwable $e) {
            return $this->asErrorJson($e->getMessage());
        }
    }
}

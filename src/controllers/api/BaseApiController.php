<?php

namespace craftnet\controllers\api;

use Craft;
use craft\elements\User;
use craft\helpers\ArrayHelper;
use craft\helpers\Db;
use craft\helpers\HtmlPurifier;
use craft\helpers\Json;
use craft\web\Controller;
use craftnet\cms\CmsLicense;
use craftnet\cms\CmsLicenseManager;
use craftnet\developers\UserBehavior;
use craftnet\errors\LicenseNotFoundException;
use craftnet\errors\ValidationException;
use craftnet\helpers\KeyHelper;
use craftnet\Module;
use craftnet\plugins\Plugin;
use craftnet\plugins\PluginLicense;
use JsonSchema\Validator;
use stdClass;
use yii\base\Exception;
use yii\base\Model;
use yii\base\UserException;
use yii\db\Expression;
use yii\helpers\Markdown;
use yii\validators\EmailValidator;
use yii\web\BadRequestHttpException;
use yii\web\Controller as YiiController;
use yii\web\HttpException;
use yii\web\Response;
use yii\web\UnauthorizedHttpException;

/**
 * Class BaseController
 *
 * @property Module $module
 */
abstract class BaseApiController extends Controller
{
    const ERROR_CODE_INVALID = 'invalid';
    const ERROR_CODE_MISSING = 'missing';
    const ERROR_CODE_MISSING_FIELD = 'missing_field';
    const ERROR_CODE_EXISTS = 'already_exists';

    const LICENSE_STATUS_VALID = 'valid';
    const LICENSE_STATUS_INVALID = 'invalid';
    const LICENSE_STATUS_MISMATCHED = 'mismatched';
    const LICENSE_STATUS_ASTRAY = 'astray';

    /**
     * @inheritdoc
     */
    public $allowAnonymous = true;

    /**
     * @inheritdoc
     */
    public $enableCsrfValidation = false;

    /**
     * The API request ID, if there is one.
     *
     * @var int|null
     */
    public $requestId;

    /**
     * The installed Craft version.
     *
     * @var string|null
     */
    public $cmsVersion;

    /**
     * The installed Craft edition.
     *
     * @var string|null
     */
    public $cmsEdition;

    /**
     * The installed plugins.
     *
     * @var Plugin[]
     */
    public $plugins = [];

    /**
     * The installed plugin versions.
     *
     * @var string[]
     */
    public $pluginVersions = [];

    /**
     * The installed plugin editions.
     *
     * @var string[]
     */
    public $pluginEditions = [];

    /**
     * The Craft license associated with this request.
     *
     * @var CmsLicense[]
     */
    public $cmsLicenses = [];

    /**
     * The plugin licenses associated with this request.
     *
     * @var PluginLicense[]
     */
    public $pluginLicenses = [];

    /**
     * @var array
     */
    private $_logRequestKeys = [];

    /**
     * @return array
     */
    public function getLogRequestKeys(): array
    {
        return $this->_logRequestKeys;
    }

    /**
     * @param $key
     * @param null $pluginHandle
     */
    public function addLogRequestKey($key, $pluginHandle = null)
    {
        if (!$pluginHandle) {
            $pluginHandle = 'craft';
        }

        $this->_logRequestKeys[$pluginHandle] = $key;
    }

    /**
     * @inheritdoc
     */
    public function runAction($id, $params = []): Response
    {
        $request = Craft::$app->getRequest();
        $requestHeaders = $request->getHeaders();
        $response = Craft::$app->getResponse();
        $responseHeaders = $response->getHeaders();
        $identity = $requestHeaders->get('X-Craft-User-Email') ?: 'anonymous';
        $db = Craft::$app->getDb();

        // was system info provided?
        if ($requestHeaders->has('X-Craft-System')) {
            foreach (explode(',', $requestHeaders->get('X-Craft-System')) as $info) {
                list($name, $installed) = explode(':', $info, 2);
                list($version, $edition) = array_pad(explode(';', $installed, 2), 2, null);
                if ($name === 'craft') {
                    $this->cmsVersion = $version;
                    $this->cmsEdition = $edition;
                } else if (strncmp($name, 'plugin-', 7) === 0) {
                    $pluginHandle = substr($name, 7);
                    $this->pluginVersions[$pluginHandle] = $version;
                    if ($edition !== null) {
                        $this->pluginEditions[$pluginHandle] = $edition;
                    }
                }
            }

            if (!empty($this->pluginVersions)) {
                $this->plugins = Plugin::find()
                    ->handle(array_keys($this->pluginVersions))
                    ->indexBy('handle')
                    ->all();
            }
        }

        $e = null;
        $cmsLicense = null;

        try {
            $cmsLicenseKey = $requestHeaders->get('X-Craft-License');
            if ($cmsLicenseKey === '🙏') {
                $cmsLicense = $this->cmsLicenses[] = $this->createCmsLicense();
                $responseHeaders
                    ->set('X-Craft-License', $cmsLicense->key)
                    ->set('X-Craft-License-Status', self::LICENSE_STATUS_VALID)
                    ->set('X-Craft-License-Domain', $cmsLicense->domain)
                    ->set('X-Craft-License-Edition', $cmsLicense->edition);

                // was a host provided with the request?
                if ($requestHeaders->has('X-Craft-Host')) {
                    $responseHeaders->set('X-Craft-Allow-Trials', (string)($cmsLicense->domain === null));
                }
            } else if ($cmsLicenseKey !== null) {
                try {
                    $cmsLicenseManager = $this->module->getCmsLicenseManager();
                    $cmsLicense = $this->cmsLicenses[] = $cmsLicenseManager->getLicenseByKey($cmsLicenseKey);
                    $cmsLicenseStatus = self::LICENSE_STATUS_VALID;
                    $cmsLicenseDomain = $oldCmsLicenseDomain = $cmsLicense->domain ? $cmsLicenseManager->normalizeDomain($cmsLicense->domain) : null;

                    // was a host provided with the request?
                    if (($host = $requestHeaders->get('X-Craft-Host')) !== null) {
                        // is it a public domain?
                        if (($domain = $cmsLicenseManager->normalizeDomain($host)) !== null) {
                            if ($cmsLicenseDomain !== null) {
                                if ($domain !== $cmsLicenseDomain) {
                                    $cmsLicenseStatus = self::LICENSE_STATUS_MISMATCHED;
                                }
                            } else {
                                // tie the license to this domain
                                $cmsLicense->domain = $cmsLicenseDomain = $domain;
                            }
                        }

                        $responseHeaders->set('X-Craft-Allow-Trials', (string)($domain === null));
                    }

                    $responseHeaders->set('X-Craft-License-Status', $cmsLicenseStatus);
                    $responseHeaders->set('X-Craft-License-Domain', $cmsLicenseDomain);
                    $responseHeaders->set('X-Craft-License-Edition', $cmsLicense->edition);

                    // update the license
                    $cmsLicense->lastActivityOn = new \DateTime();
                    if ($this->cmsVersion !== null) {
                        $cmsLicense->lastVersion = $this->cmsVersion;
                    }
                    if ($this->cmsEdition !== null) {
                        $cmsLicense->lastEdition = $this->cmsEdition;
                    }
                    $cmsLicenseManager->saveLicense($cmsLicense, false);

                    // update the history
                    if ($cmsLicenseDomain !== $oldCmsLicenseDomain) {
                        $cmsLicenseManager->addHistory($cmsLicense->id, "tied to domain {$cmsLicenseDomain} by {$identity}");
                    }
                } catch (LicenseNotFoundException $e) {
                    $responseHeaders->set('X-Craft-License-Status', self::LICENSE_STATUS_INVALID);
                    $e = null;
                }
            }

            if (($pluginLicenseKeys = $requestHeaders->get('X-Craft-Plugin-Licenses')) !== null) {
                $pluginLicenseStatuses = [];
                $pluginLicenseManager = $this->module->getPluginLicenseManager();
                foreach (explode(',', $pluginLicenseKeys) as $pluginLicenseInfo) {
                    list($pluginHandle, $pluginLicenseKey) = explode(':', $pluginLicenseInfo);
                    try {
                        $pluginLicense = $this->pluginLicenses[$pluginHandle] = $pluginLicenseManager->getLicenseByKey($pluginHandle, $pluginLicenseKey);
                        $pluginLicenseStatus = self::LICENSE_STATUS_VALID;
                        $oldCmsLicenseId = $pluginLicense->cmsLicenseId;

                        if ($cmsLicense !== null) {
                            if ($pluginLicense->cmsLicenseId) {
                                if ($pluginLicense->cmsLicenseId != $cmsLicense->id) {
                                    $pluginLicenseStatus = self::LICENSE_STATUS_MISMATCHED;
                                }
                            } else {
                                // tie the license to this Craft license
                                $pluginLicense->cmsLicenseId = $cmsLicense->id;
                            }
                        }

                        // update the license
                        $pluginLicense->lastActivityOn = new \DateTime();
                        if (isset($this->pluginVersions[$pluginHandle])) {
                            $pluginLicense->lastVersion = $this->pluginVersions[$pluginHandle];
                        }
                        $pluginLicenseManager->saveLicense($pluginLicense, false);

                        // update the history
                        if ($pluginLicense->cmsLicenseId !== $oldCmsLicenseId) {
                            $pluginLicenseManager->addHistory($pluginLicense->id, "tied to Craft license {$cmsLicense->shortKey} by {$identity}");
                        }
                    } catch (LicenseNotFoundException $e) {
                        $pluginLicenseStatus = self::LICENSE_STATUS_INVALID;
                        $e = null;
                    }

                    $pluginLicenseStatuses[] = "{$pluginHandle}:{$pluginLicenseStatus}";
                }

                if (!empty($pluginLicenseStatuses)) {
                    $responseHeaders->set('X-Craft-Plugin-License-Statuses', implode(',', $pluginLicenseStatuses));
                }
            }

            if (($result = YiiController::runAction($id, $params)) instanceof Response) {
                $response = $result;
            }
        } catch (\Throwable $e) {
            // log it and keep going
            Craft::$app->getErrorHandler()->logException($e);
            $response->setStatusCode($e instanceof HttpException && $e->statusCode ? $e->statusCode : 500);
        }

        $timestamp = Db::prepareDateForDb(new \DateTime());

        // should we update our installed plugin records?
        if ($this->cmsVersion !== null && $cmsLicense !== null) {
            // delete any installedplugins rows where lastActivity > 30 days ago
            $db->createCommand()
                ->delete('craftnet_installedplugins', [
                    'and',
                    ['craftLicenseKey' => $cmsLicense->key],
                    ['<', 'lastActivity', Db::prepareDateForDb(new \DateTime('-30 days'))],
                ])
                ->execute();

            foreach ($this->plugins as $plugin) {
                $db->createCommand()
                    ->upsert('craftnet_installedplugins', [
                        'craftLicenseKey' => $cmsLicense->key,
                        'pluginId' => $plugin->id,
                    ], [
                        'lastActivity' => $timestamp,
                    ], [], false)
                    ->execute();

                // Update the plugin's active installs count
                $db->createCommand()
                    ->update('craftnet_plugins', [
                        'activeInstalls' => new Expression('(select count(*) from [[craftnet_installedplugins]] where [[pluginId]] = :pluginId)', ['pluginId' => $plugin->id]),
                    ], [
                        'id' => $plugin->id,
                    ])
                    ->execute();
            }
        }

        // log the request
        $db->createCommand()
            ->insert('apilog.requests', [
                'method' => $request->getMethod(),
                'uri' => $request->getUrl(),
                'ip' => $request->getUserIP(),
                'action' => $this->getUniqueId().'/'.$id,
                'body' => $request->getRawBody(),
                'system' => $requestHeaders->get('X-Craft-System'),
                'platform' => $requestHeaders->get('X-Craft-Platform'),
                'host' => $requestHeaders->get('X-Craft-Host'),
                'userEmail' => $requestHeaders->get('X-Craft-User-Email'),
                'userIp' => $requestHeaders->get('X-Craft-User-Ip'),
                'timestamp' => $timestamp,
                'responseCode' => $response->getStatusCode(),
            ], false)
            ->execute();

        // get the request ID
        $this->requestId = (int)$db->getLastInsertID('apilog.requests');

        // log any licenses associated with the request
        foreach ($this->cmsLicenses as $cmsLicense) {
            $db->createCommand()
                ->insert('apilog.request_cmslicenses', [
                    'requestId' => $this->requestId,
                    'licenseId' => $cmsLicense->id,
                ], false)
                ->execute();
        }
        foreach ($this->pluginLicenses as $pluginLicense) {
            $db->createCommand()
                ->insert('apilog.request_pluginlicenses', [
                    'requestId' => $this->requestId,
                    'licenseId' => $pluginLicense->id,
                ], false)
                ->execute();
        }

        // if there was an exception, log it and return the error response
        if ($e !== null) {
            if ($e instanceof UserException && ($previous = $e->getPrevious()) !== null) {
                $logException = $previous;
            } else {
                $logException = $e;
            }

            $db->createCommand()
                ->insert('apilog.request_errors', [
                    'requestId' => $this->requestId,
                    'type' => get_class($logException),
                    'message' => $logException->getMessage(),
                    'stackTrace' => $logException->getTraceAsString(),
                ], false)
                ->execute();

            // assemble and return the response
            $data = [
                'message' => $e instanceof UserException && $e->getMessage() ? $e->getMessage() : 'Server Error',
            ];
            if ($e instanceof ValidationException) {
                $data['errors'] = $e->errors;
            }

            return $this->asJson($data);
        }

        return $response;
    }

    /**
     * Returns the JSON-decoded request body.
     *
     * @param string|null $schema JSON schema to validate the body with (optional)
     *
     * @return stdClass|array
     * @throws ValidationException if the data doesn't validate
     */
    protected function getPayload(string $schema = null)
    {
        $payload = Json::decode(Craft::$app->getRequest()->getRawBody(), false);

        if ($schema !== null && !$this->validatePayload($payload, $schema, $errors)) {
            throw new ValidationException($errors);
        }

        return $payload;
    }

    /**
     * Validates a payload against a JSON schema.
     *
     * @param stdClass $payload
     * @param string $schema
     * @param array $errors
     * @param string|null $paramPrefix
     * @return bool
     */
    protected function validatePayload(stdClass $payload, string $schema, &$errors = [], string $paramPrefix = null)
    {
        $validator = new Validator();
        $path = Craft::getAlias("@root/json-schemas/{$schema}.json");
        $validator->validate($payload, (object)['$ref' => 'file://'.$path]);

        if (!$validator->isValid()) {
            foreach ($validator->getErrors() as $error) {
                $errors[] = [
                    'param' => ($paramPrefix ? $paramPrefix.'.' : '').$error['property'],
                    'message' => $error['message'],
                    'code' => self::ERROR_CODE_INVALID,
                ];
            }

            return false;
        }

        return true;
    }

    /**
     * Returns an array of validation errors for a ValdiationException based on a model's validation errors
     *
     * @param Model $model
     * @param string|null $paramPrefix
     * @return array
     */
    protected function modelErrors(Model $model, string $paramPrefix = null): array
    {
        $errors = [];

        foreach ($model->getErrors() as $attr => $attrErrors) {
            foreach ($attrErrors as $error) {
                $errors[] = [
                    'param' => ($paramPrefix !== null ? $paramPrefix.'.' : '').$attr,
                    'message' => $error,
                    'code' => self::ERROR_CODE_INVALID,
                ];
            }
        }

        return $errors;
    }

    /**
     * @param Plugin $plugin
     * @param bool $fullDetails
     * @param bool $includePrices
     *
     * @return array
     */
    protected function transformPlugin(Plugin $plugin, bool $fullDetails = true, bool $includePrices = true): array
    {
        $icon = $plugin->getIcon();
        $developer = $plugin->getDeveloper();

        $latestRelease = Module::getInstance()->getPackageManager()->getRelease($plugin->packageName, $plugin->latestVersion);

        // Return data
        $data = [
            'id' => $plugin->id,
            'iconUrl' => $icon ? $icon->getUrl().'?'.$icon->dateModified->getTimestamp() : null,
            'handle' => $plugin->handle,
            'name' => strip_tags($plugin->name),
            'shortDescription' => $plugin->shortDescription,
            'price' => $includePrices ? $plugin->price : null,
            'renewalPrice' => $includePrices ? $plugin->renewalPrice : null,
            'currency' => 'USD',
            'developerId' => $developer->id,
            'developerName' => strip_tags($developer->getDeveloperName()),
            'categoryIds' => ArrayHelper::getColumn($plugin->getCategories(), 'id'),
            'keywords' => ($plugin->keywords ? array_map('trim', explode(',', $plugin->keywords)) : []),
            'version' => $plugin->latestVersion,
            'activeInstalls' => $plugin->activeInstalls,
            'packageName' => $plugin->packageName,
            'lastUpdate' => $latestRelease->time ?? $plugin->dateUpdated->format(\DateTime::ATOM),
        ];

        if ($fullDetails) {
            // Screenshots
            $screenshotUrls = [];
            $screenshotIds = [];

            foreach ($plugin->getScreenshots() as $screenshot) {
                $screenshotUrls[] = $screenshot->getUrl().'?'.$screenshot->dateModified->getTimestamp();
                $screenshotIds[] = $screenshot->getId();
            }

            // todo: remove this when $includePrices goes away
            $longDescription = $plugin->longDescription;
            if (!$includePrices && $plugin->price) {
                $price = Craft::$app->getFormatter()->asCurrency($plugin->price, 'USD');
                $longDescription = "_This plugin will cost {$price} once Craft 3 GA is released._\n\n{$longDescription}";
            }

            $longDescription = Markdown::process($longDescription, 'gfm');
            $longDescription = HtmlPurifier::process($longDescription);

            $data['compatibility'] = 'Craft 3';
            $data['status'] = $plugin->status;
            $data['iconId'] = $plugin->iconId;
            $data['longDescription'] = $longDescription;
            $data['documentationUrl'] = $plugin->documentationUrl;
            $data['changelogUrl'] = $plugin->getPackage()->getVcs()->getChangelogUrl();
            $data['repository'] = $plugin->repository;
            $data['license'] = $plugin->license;
            $data['developerUrl'] = $developer->developerUrl;
            $data['screenshotUrls'] = $screenshotUrls;
            $data['screenshotIds'] = $screenshotIds;
        }

        return $data;
    }

    /**
     * Authorizes the request and returns the authorized user.
     *
     * @return User|UserBehavior
     * @throws UnauthorizedHttpException if the request is not authorized
     */
    protected function getAuthUser(): User
    {
        list ($username, $password) = Craft::$app->getRequest()->getAuthCredentials();

        if (!$username || !$password) {
            throw new UnauthorizedHttpException('Not Authorized');
        }

        /** @var User|UserBehavior|null $user */
        $user = Craft::$app->getUsers()->getUserByUsernameOrEmail($username);

        if (
            $user === null ||
            $user->apiToken === null ||
            $user->getStatus() !== User::STATUS_ACTIVE ||
            Craft::$app->getSecurity()->validatePassword($password, $user->apiToken) === false
        ) {
            throw new UnauthorizedHttpException('Not Authorized');
        }

        return $user;
    }

    /**
     * Creates a new CMS license.
     *
     * @throws BadRequestHttpException
     * @throws Exception
     */
    protected function createCmsLicense(): CmsLicense
    {
        $headers = Craft::$app->getRequest()->getHeaders();
        if (($email = $headers->get('X-Craft-User-Email')) === null) {
            throw new BadRequestHttpException('Missing X-Craft-User-Email Header');
        }
        if ((new EmailValidator())->validate($email, $error) === false) {
            throw new BadRequestHttpException($error);
        }

        $license = new CmsLicense([
            'expirable' => true,
            'expired' => false,
            'autoRenew' => false,
            'edition' => CmsLicenseManager::EDITION_PERSONAL,
            'email' => $email,
            'domain' => $headers->get('X-Craft-Host'),
            'key' => KeyHelper::generateCmsKey(),
            'lastEdition' => $this->cmsEdition,
            'lastVersion' => $this->cmsVersion,
            'lastActivityOn' => new \DateTime(),
        ]);

        $manager = $this->module->getCmsLicenseManager();
        if (!$manager->saveLicense($license)) {
            throw new Exception('Could not create CMS license: '.implode(', ', $license->getErrorSummary(true)));
        }

        $note = "created by {$license->email}";
        if ($license->domain !== null) {
            $note .= " for domain {$license->domain}";
        }
        $this->module->getCmsLicenseManager()->addHistory($license->id, $note);

        return $license;
    }
}

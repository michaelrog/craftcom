<?php

namespace craftnet\oauthserver\services;

use Craft;
use craftnet\oauthserver\models\AccessToken;
use craftnet\oauthserver\Module;
use craftnet\oauthserver\records\AccessToken as AccessTokenRecord;
use yii\base\Component;

/**
 * Class AccessTokens
 */
class AccessTokens extends Component
{
    // Public Methods
    // =========================================================================

    /**
     * @return AccessToken
     * @throws \Exception
     */
    public function getAccessTokenFromRequest($request = null)
    {
        if (!$request) {
            $request = Craft::$app->getRequest();
        }

        $headers = $request->getHeaders();
        $jwt = substr($headers['Authorization'], 7);

        if ($jwt) {
            $token = Module::getInstance()->getOauth()->parseJwt($jwt);

            if ($token->isExpired()) {
                throw new \Exception("Token has expired.");
            }

            $tokenClaims = $token->getClaims();

            return $this->getAccessTokenByIdentifier($tokenClaims['jti']);
        }
    }

    /**
     * @return array
     */
    public function getAccessTokens()
    {
        $records = AccessTokenRecord::find()->orderBy('isRevoked asc')->all();
        $accessTokens = [];

        if (count($records) > 0) {
            foreach ($records as $record) {
                $accessTokens[] = new AccessToken($record->getAttributes());
            }
        }
        return $accessTokens;
    }

    /**
     * @param $id
     *
     * @return AccessToken
     */
    public function getAccessTokenById($id)
    {
        if ($id) {
            $record = AccessTokenRecord::findOne($id);

            if ($record) {
                return new AccessToken($record->getAttributes());
            }
        }
    }

    /**
     * @param $identifier
     * @param bool $isRevoked
     *
     * @return AccessToken
     */
    public function getAccessTokenByIdentifier($identifier, $isRevoked = false)
    {
        $record = AccessTokenRecord::findOne(['identifier' => $identifier, 'isRevoked' => $isRevoked]);

        if ($record) {
            return new AccessToken($record->getAttributes());
        }
    }

    /**
     * @return array
     */
    public function getRevokedAccessTokens()
    {
        $records = AccessTokenRecord::findAll(['isRevoked' => 1]);

        $tokens = [];

        foreach ($records as $record) {
            $tokens[] = new AccessToken($record->getAttributes());
        }

        return $tokens;
    }

    /**
     * @param AccessToken $model
     *
     * @return bool
     */
    public function saveAccessToken(AccessToken &$model)
    {
        // is new ?
        $isNewAccessToken = !$model->id;

        // populate record
        $record = $this->_getAccessTokenRecordById($model->id);
        $record->clientId = $model->clientId;
        $record->userId = $model->userId;
        $record->identifier = $model->identifier;
        $record->expiryDate = $model->expiryDate;
        $record->scopes = $model->scopes;
        $record->isRevoked = $model->isRevoked;

        // save record
        if ($record->save(false)) {
            // populate id
            if ($isNewAccessToken) {
                $model->id = $record->id;
            }
            return true;
        } else {
            return false;
        }
    }

    /**
     * Deletes a $accessToken by its ID.
     *
     * @param int $accessTokenId
     *
     * @return bool Whether the $accessToken was deleted successfully
     * @throws \Exception if reasons
     */
    public function deleteAccessTokenById(int $accessTokenId): bool
    {
        $accessToken = $this->getAccessTokenById($accessTokenId);

        if (!$accessToken) {
            return false;
        }

        Craft::$app->getDb()->createCommand()
            ->delete('{{%oauthserver_access_tokens}}', ['id' => $accessTokenId])
            ->execute();

        return true;
    }

    /**
     * @return bool
     */
    public function clearAccessTokens()
    {
        Craft::$app->getDb()->createCommand()
            ->delete('{{%oauthserver_access_tokens}}')
            ->execute();

        return true;
    }

    // Private Methods
    // =========================================================================

    /**
     * @param null $id
     *
     * @return AccessTokenRecord|static
     * @throws \Exception
     */
    private function _getAccessTokenRecordById($id = null)
    {
        if ($id) {
            $record = AccessTokenRecord::findOne($id);
            if (!$record) {
                throw new \Exception(Craft::t('app', 'No access token exists with the ID “{id}”', ['id' => $id]));
            }
        } else {
            $record = new AccessTokenRecord();
        }
        return $record;
    }
}

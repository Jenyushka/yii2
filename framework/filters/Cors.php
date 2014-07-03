<?php
/**
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace yii\filters;

use Yii;
use yii\base\ActionFilter;
use yii\web\Request;
use yii\web\Response;

/**
 * Cors filter implements [Cross Origin Resource Sharing](http://en.wikipedia.org/wiki/Cross-origin_resource_sharing).
 * Make sure to read carefully what CORS does and does not. CORS do not secure your API,
 * but allow the developper to grant access to third party code (ajax calls from external domain)
 *
 * You may use CORS filter by attaching it as a behavior to a controller or module, like the following,
 *
 * ```php
 * public function behaviors()
 * {
 *     return [
 *         'corsFilter' => [
 *             'class' => \yii\filters\Cors::className(),
 *         ],
 *     ];
 * }
 * ```
 *
 * The CORS filter can be specialized to restrict parameters, like this,
 * [MDN CORS Information](https://developer.mozilla.org/en-US/docs/Web/HTTP/Access_control_CORS)
 *
 * ```php
 * public function behaviors()
 * {
 *     return [
 *         'corsFilter' => [
 *             'class' => \yii\filters\Cors::className(),
 *             // restrict access to
 *             'Origin' => ['http://www.myserver.com', 'https://www.myserver.com'],
 *             'Access-Control-Request-Method' => ['POST', 'PUT'],
 *             // Allow only POST and PUT methods
 *             'Access-Control-Request-Headers' => ['X-Wsse'],
 *             // Allow only headers 'X-Wsse'
 *             'Access-Control-Allow-Credentials' => true,
 *             // Allow OPTIONS caching
 *             'Access-Control-Max-Age' => 3600,
 *
 *         ],
 *     ];
 * }
 * ```
 *
 *
 * @author Philippe Gaultier <pgaultier@gmail.com>
 * @since 2.0
 */
class Cors extends ActionFilter
{
    /**
     * @var Request the current request. If not set, the `request` application component will be used.
     */
    public $request;
    /**
     * @var Response the response to be sent. If not set, the `response` application component will be used.
     */
    public $response;
    /**
     * @var array define specific CORS rules for specific actions
     */
    public $actions = [];
    /**
     * @var array Basic headers handled for the CORS requests.
     */
    public $cors = [
        'Origin' => ['*'],
        'Access-Control-Request-Method' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'],
        'Access-Control-Request-Headers' => ['*'],
        'Access-Control-Allow-Credentials' => true,
        'Access-Control-Max-Age' => 86400,
    ];

    /**
     * @inheritdoc
     */
    public function beforeAction($action)
    {
        $this->request = Yii::$app->getRequest();
        $this->response = Yii::$app->getResponse();
        $this->overrideDefaultSettings($action);

        $requestCorsHeaders = $this->extractHeaders($this->request);
        $responseCorsHeaders = $this->prepareHeaders($requestCorsHeaders);
        $this->addCorsHeaders($this->response, $responseCorsHeaders);

        return true;
    }

    /**
     * Override settings for specific action
     * @param \yii\base\Action $action the action settings to override
     */
    public function overrideDefaultSettings($action)
    {
        if (isset($this->actions[$action->id])) {
            $actionParams = $this->actions[$action->id];
            foreach ($this->cors as $headerField => $headerValue) {
                if (isset($actionParams[$headerField])) {
                    $this->cors[$headerField] = $actionParams[$headerField];
                }
            }
        }
    }

    /**
     * Extract CORS headers fron the request
     * @param Request $request
     * @return array CORS headers to handle
     */
    public function extractHeaders($request)
    {
        $headers = [];
        $requestHeaders = array_keys($this->cors);
        foreach ($requestHeaders as $headerField) {
            $serverField = static::headerizeToPhp($headerField);
            $headerData = isset($_SERVER[$serverField])?$_SERVER[$serverField]:null;
            if ($headerData !== null) {
                $headers[$headerField] = $headerData;
            }
        }
        return $headers;
    }

    /**
     * For each CORS headers create the specific response
     * @param array $requestHeaders CORS headers we have detected
     * @return array CORS headers ready to be sent
     */
    public function prepareHeaders($requestHeaders)
    {
        $responseHeaders = [];
        // handle Origin
        if (isset($requestHeaders['Origin'])) {
            if ((in_array('*', $this->cors['Origin']) === true)
             || (in_array($requestHeaders['Origin'], $this->cors['Origin']))) {
                $responseHeaders['Access-Control-Allow-Origin'] = $requestHeaders['Origin'];
            }
        }

        $this->prepareAllowHeaders('Method', $requestHeaders, $responseHeaders);
        $this->prepareAllowHeaders('Headers', $requestHeaders, $responseHeaders);

        if ($this->cors['Access-Control-Allow-Credentials'] === true) {
            $responseHeaders['Access-Control-Allow-Credentials'] = 'true';
        } elseif ($this->cors['Access-Control-Allow-Credentials'] === false) {
            $responseHeaders['Access-Control-Allow-Credentials'] = 'false';
        }
        if (($_SERVER['REQUEST_METHOD'] === 'OPTIONS') && ($this->cors['Access-Control-Max-Age'] !== null)) {
            $responseHeaders['Access-Control-Max-Age'] = $this->cors['Access-Control-Max-Age'];
        }

        return $responseHeaders;
    }

    /**
     * Handle classic CORS request to avoid duplicate code
     * @param string $type the kind of headers we would handle
     * @param array $requestHeaders CORS headers request by client
     * @param array $responseHeaders CORS response headers sent to the clinet
     */
    protected function prepareAllowHeaders($type, $requestHeaders, &$responseHeaders)
    {
        $requestHeaderField = 'Access-Control-Request-'.$type;
        $responseHeaderField = 'Access-Control-Allow-'.$type;
        if (isset($requestHeaders[$requestHeaderField])) {
            if (in_array('*', $this->cors[$requestHeaderField])) {
                if ($type === 'Method') {
                    $responseHeaders[$responseHeaderField] = strtoupper($responseHeaders[$responseHeaderField]);
                } elseif ($type === 'Headers') {
                    $responseHeaders[$responseHeaderField] = static::headerize($responseHeaders[$responseHeaderField]);
                }
            } else {
                $requestedData = preg_split("/[\s,]+/", $requestHeaders[$requestHeaderField], -1, PREG_SPLIT_NO_EMPTY);
                $acceptedData = [];
                foreach ($requestedData as $req) {
                    if ($type === 'Method') {
                        $req = strtoupper($req);
                    } elseif ($type === 'Headers') {
                        // ucwords
                        $req = static::headerize($req);
                    }
                    if (in_array($req, $this->cors[$requestHeaderField])) {
                        $acceptedData[] = $req;
                    }
                }
                if (empty($acceptedData) === false) {
                    $responseHeaders[$responseHeaderField] = implode(', ', $acceptedData);
                }
            }
        }
    }

    /**
     * Adds the CORS headers to the response
     * @param Response $response
     * @param array CORS headers which have been compouted
     */
    public function addCorsHeaders($response, $headers)
    {
        if (empty($headers) === false) {
            $responseHeaders = $response->getHeaders();
            foreach ($headers as $field => $value) {
                $responseHeaders->set($field, $value);
            }
        }
    }

    /**
     * Convert any string (including php headers with HTTP prefix) to header format like :
     *  * X-PINGOTHER -> X-Pingother
     *  * HTTP_X_PINGOTHER -> X-Pingother
     * @param string $string string to convert
     * @return string the result in "header" format
     */
    protected static function headerize($string)
    {
        return str_replace(' ', '-', ucwords(strtolower(str_replace(['_', '-'], [' ', ' '], $string))));
    }

    /**
     * Convert any string (including php headers with HTTP prefix) to header format like :
     *  * X-Pingother -> HTTP_X_PINGOTHER
     *  * X PINGOTHER -> HTTP_X_PINGOTHER
     * @param string $string string to convert
     * @return string the result in "php $_SERVER header" format
     */
    protected static function headerizeToPhp($string)
    {
        return 'HTTP_'.strtoupper(str_replace([' ', '-'], ['_', '_'], $string));
    }
}

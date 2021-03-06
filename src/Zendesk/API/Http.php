<?php

namespace Zendesk\API;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\LazyOpenStream;
use GuzzleHttp\Psr7\Request;
use Zendesk\API\Exceptions\ApiResponseException;
use Zendesk\API\Exceptions\AuthException;

/**
 * HTTP functions via curl
 * @package Zendesk\API
 */
class Http
{
    public static $curl;

    /**
     * Prepares an endpoint URL with optional side-loading
     *
     * @param array $sideload
     * @param array $iterators
     *
     * @return string
     */
    public static function prepareQueryParams(array $sideload = null, array $iterators = null)
    {
        $addParams = [];
        // First look for side-loaded variables
        if (is_array($sideload)) {
            $addParams['include'] = implode(',', $sideload);
        }

        // Next look for special collection iterators
        if (is_array($iterators)) {
            foreach ($iterators as $k => $v) {
                if (in_array($k, ['per_page', 'page', 'sort_order', 'sort_by', 'external_id'])) {
                    $addParams[$k] = $v;
                }
            }
        }

        return $addParams;
    }

    /**
     * Use the send method to call every endpoint except for oauth/tokens
     *
     * @param HttpClient $client
     * @param string     $endPoint E.g. "/tickets.json"
     * @param array      $options
     *                             Available options are listed below:
     *                             array $queryParams Array of unencoded key-value pairs, e.g. ["ids" => "1,2,3,4"]
     *                             array $postFields Array of unencoded key-value pairs, e.g. ["filename" => "blah.png"]
     *                             string $method "GET", "POST", etc. Default is GET.
     *                             string $contentType Default is "application/json"
     *
     * @return array The response body, parsed from JSON into an associative array
     * @throws ApiResponseException
     * @throws AuthException
     */
    public static function send(
        HttpClient $client,
        $endPoint,
        $options = []
    ) {
        $options = array_merge(
            [
                'method'      => 'GET',
                'contentType' => 'application/json',
                'postFields'  => null,
                'queryParams' => null
            ],
            $options
        );

        $headers = [
            'Accept'       => 'application/json',
            'Content-Type' => $options['contentType'],
            'User-Agent'   => $client->getUserAgent()
        ];

        $request = new Request(
            $options['method'],
            $client->getApiUrl() . $endPoint,
            $headers
        );

        $requestOptions = [];

        if (! empty($options['multipart'])) {
            $request                     = $request->withoutHeader('Content-Type');
            $requestOptions['multipart'] = $options['multipart'];
        } elseif (! empty($options['postFields'])) {
            $request = $request->withBody(\GuzzleHttp\Psr7\stream_for(json_encode($options['postFields'])));
        } elseif (! empty($options['file'])) {
            if (is_file($options['file'])) {
                $fileStream = new LazyOpenStream($options['file'], 'r');
                $request    = $request->withBody($fileStream);
            }
        }

        if (! empty($options['queryParams'])) {
            foreach ($options['queryParams'] as $queryKey => $queryValue) {
                $uri     = $request->getUri();
                $uri     = $uri->withQueryValue($uri, $queryKey, $queryValue);
                $request = $request->withUri($uri, true);
            }
        }

        try {
            list ($request, $requestOptions) = $client->getAuth()->prepareRequest($request, $requestOptions);
            $response = $client->guzzle->send($request, $requestOptions);
        } catch (RequestException $e) {
            throw new ApiResponseException($e);
        } finally {
            $client->setDebug(
                $request->getHeaders(),
                $request->getBody()->getContents(),
                isset($response) ? $response->getStatusCode() : null,
                isset($response) ? $response->getHeaders() : null,
                isset($e) ? $e : null
            );

            $request->getBody()->rewind();
        }

        if (isset($file)) {
            fclose($file);
        }

        $client->setSideload(null);

        return json_decode($response->getBody()->getContents());
    }
}

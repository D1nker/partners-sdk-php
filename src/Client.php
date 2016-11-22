<?php

namespace Creads\Partners;

use GuzzleHttp\Client as GuzzleClient;

class Client extends GuzzleClient
{
    /**
     * The API format to send/recieve : json, xml...
     *
     * @var string
     */
    protected $format = 'json';

    /**
     * If fetched, the user's data needed to upload files.
     *
     * @var array|null
     */
    protected $uploadForm = null;

    /**
     * Constructor
     * {@inheritdoc}
     */
    public function __construct(AuthenticationInterface $authentication, array $config = [])
    {
        $config = array_merge($this->getDefaultClientConfig(), $config);

        if ($authentication) {
            $config = array_merge($config, $authentication->getConfig());
        }
        if (!empty($config['format']) && in_array($config['format'], ['json'])) {
            $this->format = $config['format'];
        }
        parent::__construct($config);
    }

    /**
     * Get default configuration to apply the client.
     *
     * @return array
     */
    protected function getDefaultClientConfig()
    {
        return [
            'base_uri' => 'https://api.creads-partners.com/v1/',
        ];
    }

    public function put($uri, $body = [], $options = [])
    {
        $requestBody = array_merge($options, [$this->format => $body]);

        return parent::request('PUT', $uri, $requestBody);
    }

    public function post($uri, $body = [], $options = [])
    {
        $requestBody = array_merge($options, [$this->format => $body]);

        return parent::request('POST', $uri, $requestBody);
    }

    public function get($uri)
    {
        $response = parent::get($uri);
        switch ($this->format) {
            case 'json':
            default:
                $parsedResponse = json_decode($response->getBody(), true);
                break;
        }

        return $parsedResponse;
    }

    public function postFile($sourceFilepath, $destinationFilepath = null)
    {
        if (!$destinationFilepath) {
            // No specified filename, use the uploaded one
            $destinationFilepath = pathinfo($sourceFilepath)['basename'];
        }

        $uploadForm = $this->getUploadForm();
        $uploadUrl = $uploadForm['form_attributes']['action'];
        $uploadUrl = str_replace('${filename}', $destinationFilepath, $uploadUrl);

        $multipartBody = [];

        // Add Amazon specific data needed for authentication (order matters)
        foreach ($uploadForm['form_inputs'] as $key => $value) {
            if ($key === 'key') {
                $value = str_replace('${filename}', $destinationFilepath, $value);
            }
            $multipartBody[] = [
                'name' => $key,
                'contents' => $value,
            ];
        }

        // Build the multipart file upload (order matters)
        $multipartBody[] = [
            'name' => 'file',
            'contents' => fopen($sourceFilepath, 'rb'),
        ];

        return $this->request(
            'POST',
            $uploadUrl,
            [
                'headers' => [
                    'Authorization' => null,
                ],
                'multipart' => $multipartBody,
            ]
        );
    }

    protected function getUploadForm()
    {
        if ($this->uploadForm) {
            // If previous upload form is not expired yet
            // use data already fetched
            // If not, fetch new upload form
            $expireAt = new \DateTime($this->uploadForm['expires_at'], new \DateTimeZone('UTC'));
            $now = new \DateTime('now', new \DateTimeZone('UTC'));
            if ($now <= $expireAt) {
                return $this->uploadForm;
            }
        }

        $me = $this->get('me');
        if (!isset($me['upload_form'])) {
            throw new \Exception('You are not allowed to upload files');
        }

        $this->uploadForm = $me['upload_form'];

        return $this->uploadForm;
    }
}

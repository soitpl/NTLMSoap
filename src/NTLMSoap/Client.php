<?php

namespace soIT\NTLMSoap;

use Exception;
use SoapFault;

/**
 * Base on https://github.com/mlabrum/NTLMSoap
 */
class Client extends \SoapClient
{
    private $options = [];

    /**
     * @var string Set protocol
     */
    private $protocol = 'http';

    /**
     * @param String $url The WSDL url
     * @param array $data Soap options
     * @param LoggerInterface $logger
     *
     * @throws SoapFault
     * @see \SoapClient::__construct()
     */
    public function __construct(string $url, array $data)
    {
        $this->setProtocol($data['protocol'] ?? 'http');

        $this->options = $data;

        if (empty($data['username']) && empty($data['password'])) {
            parent::__construct($url, $data);
        } else {
            $this->use_ntlm = true;

            HttpStream\NTLM::$user = $data['username'];
            HttpStream\NTLM::$password = $data['password'];

            $this->registerWrapper();

            parent::__construct($url, $data);

            stream_wrapper_restore($this->getProtocol());
        }
    }

    /**
     * @param $request
     * @param $location
     * @param $action
     * @param $version
     * @param int $one_way
     *
     * @return bool|string
     * @see SoapClient::__doRequest()
     */
    public function __doRequest(
        $request,
        $location,
        $action,
        $version,
        $one_way = 0
    ) {
        $this->__last_request = $request;
        $start_time = microtime(true);

        $ch = curl_init($location);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Method: POST',
            'User-Agent: PHP-SOAP-CURL',
            'Content-Type: text/xml; charset=utf-8',
            'SOAPAction: "' . $action . '"'
        ]);

        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $request);
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);

        if (
            !empty($this->options['username']) &&
            !empty($this->options['password'])
        ) {
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_NTLM);
            curl_setopt(
                $ch,
                CURLOPT_USERPWD,
                $this->options['username'] . ':' . $this->options['password']
            );
        }

        $response = curl_exec($ch);

        // Log as an error if the curl call isn't a success
        $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $log_func = $http_status == 200 ? 'debug' : 'error';

        // Log the call
        $this->logger->$log_func("SoapCall: " . $action, [
            "Location" => $location,
            "HttpStatus" => $http_status,
            "Request" => $request,
            "Response" =>
                strlen($response) > 2000
                    ? substr($response, 0, 2000) . "..."
                    : $response,
            "RequestTime" => curl_getinfo($ch, CURLINFO_TOTAL_TIME),
            "RequestConnectTime" => curl_getinfo($ch, CURLINFO_CONNECT_TIME),
            "Time" => microtime(true) - $start_time
        ]);

        return $response;
    }

    /**
     * @return string
     */
    public function getProtocol(): string
    {
        return $this->protocol;
    }

    /**
     * @param string $protocol
     */
    public function setProtocol(string $protocol): void
    {
        if ($protocol == 'http' || $protocol == 'https') {
            $this->protocol = $protocol;
        }
    }

    /**
     * @throws Exception
     */
    private function registerWrapper(): void
    {
        stream_wrapper_unregister($this->getProtocol());
        if (
            !stream_wrapper_register(
                $this->getProtocol(),
                'soIT\\NTLMSoap\\HttpStream\\NTLM'
            )
        ) {
            throw new Exception(
                "Unable to register " .
                    strtoupper($this->getProtocol()) .
                    " Handler"
            );
        }
    }
}

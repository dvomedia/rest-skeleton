<?php

namespace DVO\Controller;

use Silex\Application;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;

use PhpAmqpLib\Connection\AMQPConnection;
use PhpAmqpLib\Message\AMQPMessage;

class RpcController extends AbstractController
{

    /**
     * Create a new user with a POST request
     *
     * @param  Request      $request
     * @param  Application  $app
     * @return JsonResponse
     */
    public function serverJsonAction(Request $request, Application $app)
    {
        $connection = new AMQPConnection('localhost', 5672, 'guest', 'guest');
        $channel    = $connection->channel();

        $channel->queue_declare('rpc_queue', false, false, false, false);

        echo " [x] Awaiting RPC requests\n";
        $callback = function ($req) use ($app) {
            $message = json_decode($req->body, true);
            echo " [.] " . print_r($message, true) . "\n";

            $request = Request::create($message['path'], $message['method']);
            $response = $app->handle($request);
            var_dump($response);


            $msg = new AMQPMessage(
                $response->getContent(),
                array('correlation_id' => $req->get('correlation_id'))
            );

            $req->delivery_info['channel']->basic_publish(
                $msg,
                '',
                $req->get('reply_to')
            );
            $req->delivery_info['channel']->basic_ack(
                $req->delivery_info['delivery_tag']
            );
        };

        $channel->basic_qos(null, 1, null);
        $channel->basic_consume('rpc_queue', '', false, false, false, false, $callback);

        while (count($channel->callbacks)) {
            $channel->wait();
        }

        $channel->close();
        $connection->close();
    }


    /**
     * Handle unexpected responses
     *
     * @param  array        $error
     * @return JsonResponse
     */
    public function errorJsonResponse(array $error)
    {
        return new JsonResponse($error);
    }
}
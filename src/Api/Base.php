<?php

declare(strict_types=1);

namespace CarmeloSantana\OllamaPress\Api;

use CarmeloSantana\OllamaPress\Api\Status;
use \WP_Error;
use \WP_REST_Request;
use \WP_REST_Response;

abstract class Base
{
    public const NAMESPACE = '/' . OP . '/v1';

    public const PERMISSION_READ = 'read';

    public const PERMISSION_WRITE = 'edit_posts';

    /**
     * Check permissions for the posts.
     *
     * @param WP_REST_Request $request Current request.
     */
    public function get_item_permissions_check($request)
    {
        if (!current_user_can(self::PERMISSION_READ)) {
            return new WP_Error('rest_forbidden', esc_html__('You cannot access this resource.'), ['status' => $this->authorization_status_code()]);
        }
        return true;
    }

    /**
     * Check permissions for the posts.
     *
     * @param WP_REST_Request $request Current request.
     */
    public function update_item_permissions_check($request)
    {
        if (!current_user_can(self::PERMISSION_WRITE)) {
            return new WP_Error('rest_forbidden', esc_html__('You cannot modify this resource.'), ['status' => $this->authorization_status_code()]);
        }
        return true;
    }

    // Sets up the proper HTTP status code for authorization.
    public function authorization_status_code()
    {
        $status = Status::UNAUTHORIZED;

        if (is_user_logged_in()) {
            $status = Status::FORBIDDEN;
        }

        return $status;
    }

    /**
     * Prepare a response for inserting into a collection of responses.
     *
     * This is copied from WP_REST_Controller class in the WP REST API v2 plugin.
     *
     * @param WP_REST_Response $response Response object.
     * @return array Response data, ready for insertion into collection data.
     */
    public function prepare_response_for_collection($response)
    {
        if (!($response instanceof \WP_REST_Response)) {
            return $response;
        }

        $data = (array) $response->get_data();

        // TODO: Good place to hook _links.
        return $data;
    }
}

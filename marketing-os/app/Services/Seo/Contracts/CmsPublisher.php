<?php

namespace App\Services\Seo\Contracts;

interface CmsPublisher
{
    public function name(): string;

    /**
     * @param  array{base_url:string,username:string,app_password:string}  $credentials
     * @return array{ok:bool,message:string}
     */
    public function testConnection(array $credentials): array;

    /**
     * @param  array{base_url:string,username:string,app_password:string}  $credentials
     * @param  array{title:string,slug?:string,body_html:string,status?:string,meta_title?:string,meta_description?:string}  $post
     * @return array{ok:bool,external_id?:string,url?:string,message:string}
     */
    public function publish(array $credentials, array $post): array;
}

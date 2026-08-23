<?php

namespace App\Services\Festivals;

final class FestivalEventData
{
    /**
     * @param  list<string>  $suggested_angles
     */
    public function __construct(
        public string $name,
        public string $occurs_on,
        public string $category,
        public array $suggested_angles,
        public string $source = 'config',
    ) {}

    /**
     * @return array{name:string,occurs_on:string,category:string,suggested_angles:list<string>,source:string}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'occurs_on' => $this->occurs_on,
            'category' => $this->category,
            'suggested_angles' => $this->suggested_angles,
            'source' => $this->source,
        ];
    }
}

<?php

namespace Correios\Includes;

readonly class Product
{
    public function __construct(
        private float $weight = 0,
        private float $width = 0,
        private float $height = 0,
        private float $length = 0,
        private float $diameter = 0,
        private float $cubicWeight = 0,
        private int $objectType = 1,
    ) {}

    public function getWeight(): float
    {
        return $this->weight;
    }

    public function getWidth(): float
    {
        return $this->width;
    }

    public function getHeight(): float
    {
        return $this->height;
    }

    public function getLength(): float
    {
        return $this->length;
    }

    public function getDiameter(): float
    {
        return $this->diameter;
    }

    public function getCubicWeight(): float
    {
        return $this->cubicWeight;
    }

    public function getObjectType(): int
    {
        return $this->objectType;
    }
}

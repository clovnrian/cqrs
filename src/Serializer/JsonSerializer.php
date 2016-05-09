<?php

namespace CQRS\Serializer;

class JsonSerializer implements SerializerInterface
{
    /**
     * @param mixed $data
     * @return string
     */
    public function serialize($data): string
    {
        return json_encode($data);
    }

    /**
     * @param string $data
     * @param string $type
     * @return mixed
     */
    public function deserialize(string $data, string $type)
    {
        $data = json_decode($data, true);

        return method_exists($type, 'jsonDeserialize')
            ? $type::jsonDeserialize($data)
            : new $type($data);
    }
}

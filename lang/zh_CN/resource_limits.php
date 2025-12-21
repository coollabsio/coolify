<?php

return [
    'title' => '资源限制',
    'description' => '通过 CPU 和内存限制您的容器资源。',
    'cpu_title' => '限制 CPU',
    'cpu_count_label' => 'CPU 数量',
    'cpu_count_helper' => '0 表示使用所有 CPU。浮点数，如 0.002 或 1.5。更多信息 <a class="underline dark:text-white" target="_blank" href="https://docs.docker.com/engine/reference/run/#cpu-share-constraint">此处</a>。',
    'cpu_sets_label' => '要使用的 CPU 集',
    'cpu_sets_helper' => '空表示使用所有 CPU 集。0-2 将使用 CPU 0, CPU 1 和 CPU 2。更多信息 <a class="underline dark:text-white"  target="_blank" href="https://docs.docker.com/engine/reference/run/#cpu-share-constraint">此处</a>。',
    'cpu_weight_label' => 'CPU 权重',
    'cpu_weight_helper' => '更多信息 <a class="underline dark:text-white" target="_blank" href="https://docs.docker.com/engine/reference/run/#cpu-share-constraint">此处</a>。',
    'memory_title' => '限制内存',
    'memory_soft_label' => '软内存限制',
    'memory_soft_helper' => '示例：69b (字节) 或 420k (千字节) 或 1337m (兆字节) 或 1g (千兆字节)。<br>更多信息 <a class="underline dark:text-white" target="_blank" href="https://docs.docker.com/compose/compose-file/05-services/#mem_reservation">此处</a>。',
    'swappiness_label' => 'Swappiness',
    'swappiness_helper' => '0-100。<br>更多信息 <a class="underline dark:text-white" target="_blank" href="https://docs.docker.com/compose/compose-file/05-services/#mem_swappiness">此处</a>。',
    'memory_max_label' => '最大内存限制',
    'memory_max_helper' => '示例：69b (字节) 或 420k (千字节) 或 1337m (兆字节) 或 1g (千兆字节)。<br>更多信息 <a class="underline dark:text-white" target="_blank" href="https://docs.docker.com/compose/compose-file/05-services/#mem_limit">此处</a>。',
    'swap_max_label' => '最大交换限制',
    'swap_max_helper' => '示例：69b (字节) 或 420k (千字节) 或 1337m (兆字节) 或 1g (千兆字节)。<br>更多信息 <a class="underline dark:text-white" target="_blank" href="https://docs.docker.com/compose/compose-file/05-services/#memswap_limit">此处</a>。',
];

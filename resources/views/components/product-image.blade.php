<x-pc-part-visual
    :src="$product->image"
    :alt="$product->title"
    :type="$product->component_type"
    {{ $attributes }}
/>

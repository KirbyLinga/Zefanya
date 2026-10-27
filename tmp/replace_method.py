with open('c:/Web/Zefanya/app/Services/ParcelReturnService.php', 'r') as f:
    lines = f.readlines()

# New method lines (each line as a Python string, with proper escaping)
new_method_lines = [
    "    private function restoreReturnStock(SellerOrder $sellerOrder): bool\n",
    "    {\n",
    "        $sellerOrder->loadMissing('orderItems');\n",
    "        $variantIds = $sellerOrder->orderItems->pluck('variant_id')->filter()->unique()->sort()->values();\n",
    "        if ($variantIds->isEmpty()) {\n",
    "            return false;\n",
    "        }\n",
    "\n",
    "        // Lock all variants in a consistent order to prevent deadlocks.\n",
    "        $variants = ProductVariant::query()->whereIn('id', $variantIds)\n",
    "            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');\n",
    "\n",
    "        $allRestored = true;\n",
    "        $anyRestored = false;\n",
    "        foreach ($variantIds as $variantId) {\n",
    "            $variant = $variants->get($variantId);\n",
    "            if ($variant === null) {\n",
    "                // Variant permanently missing: record exception and fail restoration.\n",
    "                $alreadyExcepted = InventoryException::query()->where('seller_order_id', $sellerOrder->id)\n",
    "                    ->where('variant_id', $variantId)->exists();\n",
    "                if (!$alreadyExcepted) {\n",
    "                    InventoryException::create([\n",
    "                        'seller_order_id' => $sellerOrder->id,\n",
    "                        'variant_id' => $variantId,\n",
    "                        'quantity' => 0,\n",
    "                        'status' => InventoryException::OPEN,\n",
    "                    ]);\n",
    "                }\n",
    "                $allRestored = false;\n",
    "                continue;\n",
    "            }\n",
    "            $totalQty = $sellerOrder->orderItems->where('variant_id', $variantId)->sum('quantity');\n",
    "            $result = app(StockService::class)->set(\n",
    "                $variant,\n",
    "                (int) $variant->stock_quantity + (int) $totalQty,\n",
    "                'return',\n",
    "                $sellerOrder->id,\n",
    "            );\n",
    "            if ($result !== null) {\n",
    "                $anyRestored = true;\n",
    "            } else {\n",
    "                $allRestored = false;\n",
    "            }\n",
    "        }\n",
    "\n",
    "        if ($anyRestored && $allRestored) {\n",
    "            Product::syncRollupsForProducts(\n",
    "                $sellerOrder->orderItems->pluck('product_id')->unique()->values(),\n",
    "            );\n",
    "        }\n",
    "\n",
    "        return $allRestored;\n",
    "    }\n",
]

# Rebuild: lines 0-311 + new + lines 350 onwards (0-indexed: lines[:311] + new + lines[349:])
new_lines = lines[:311] + new_method_lines + lines[349:]

with open('c:/Web/Zefanya/app/Services/ParcelReturnService.php', 'w') as f:
    f.writelines(new_lines)

print('Done, new file has', len(new_lines), 'lines')
<?php

$path = 'app/Models/SellerOrder.php';
$content = file_get_contents($path);

$anchor = '    public function statusHistories(): HasMany'."\n".
          '    {'."\n".
          "        return \$this->hasMany(StatusHistory::class)->orderBy('created_at');\n".
          '    }';

$addition = $anchor."\n\n".
    '/**'."\n".
    '     * Lost/damaged incidents reported for this delivery parcel.'."\n".
    '     */'."\n".
    '    public function incidentSet()'."\n".
    '    {'."\n".
    "        return \$this->hasMany(Incidents::class, 'incident_seller_order_id');\n".
    '    }';

if (strpos($content, $anchor) === false) {
    echo "ANCHOR_NOT_FOUND\n";
    exit(1);
}

if (strpos($content, 'incidentSet') !== false) {
    echo "ALREADY_PRESENT\n";
    exit(0);
}

file_put_contents($path, str_replace($anchor, $addition, $content));
echo "INSERTED\n";

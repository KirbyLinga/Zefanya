<?php

namespace Database\Factories\Catalog;

/**
 * Static catalog data for ProductFactory.
 *
 * Kept separate so the name/price/description tables stay readable. Names are
 * keyed by `categories.label` — the `categories` table has `icon` + `label`,
 * there is no `name` column — so a product's name matches the category it is
 * filed under.
 */
final class ProductCatalogData
{
    /**
     * @var array<string, list<string>>
     */
    private const NAMES = [
        'Books and Media' => [
            'Hardbound Filipino Classics Box Set',
            'Watercolor Sketchbook A4 200gsm',
            'Pearlescent Gel Pen Set of 12',
            'Vinyl Record: OPM Acoustic Sessions',
        ],
        'Health and Beauty' => [
            'Vitamin C Brightening Serum 30ml',
            'Niacinamide Facial Toner 250ml',
            'Shea Butter Body Lotion 500ml',
            'Argan Oil Hair Repair Mask',
        ],
        'Sports and Outdoors' => [
            'Non-Slip Yoga Mat 6mm',
            'Adjustable Dumbbell Set 20kg',
            'Insulated Sports Bottle 1L',
            'Foldable Camping Chair',
        ],
        'Home and Garden' => [
            'Ceramic Planter Pot with Drainage',
            'Linen Throw Pillow Cover Set',
            'Indoor Herb Garden Starter Kit',
            'Aromatherapy Scented Candle Trio',
        ],
        'Kids and Baby' => [
            'Organic Cotton Baby Onesie 3-Pack',
            'Wooden Stacking Blocks Set',
            'Reusable Cloth Diaper with Inserts',
            'Soft Plush Bear with Name Embroidery',
        ],
        'Men\'s Apparel' => [
            'Everyday Cotton Crew Neck Tee',
            'Slim-Fit Stretch Chino Pants',
            'Water-Repellent Bomber Jacket',
            'Pique Polo Shirt Classic Fit',
        ],
        'Women\'s Apparel' => [
            'Linen Wrap Midi Dress',
            'High-Waist Straight Leg Jeans',
            'Ribbed Knit Cropped Cardigan',
            'Satin Blouse with Neck Tie',
        ],
        'Electronics and Gadgets' => [
            'True Wireless Earbuds with Case',
            '20000mAh Fast-Charge Power Bank',
            'Mechanical Keyboard 65% Hot-Swap',
            '7-in-1 USB-C Docking Hub',
        ],
        'Pet Supplies' => [
            'Grain-Free Dry Dog Food 3kg',
            'Cat Scratching Post with Hammock',
            'Reflective Adjustable Dog Harness',
            'Chew-Resistant Rubber Dog Toy',
        ],
        'Food and Gourmet' => [
            'Single-Origin Coffee Beans 500g',
            'Wild Honey 100% Raw 750ml',
            'Dried Mango Premium Pack 500g',
            'Artisan Dark Chocolate 70% Bar',
        ],
        'Automotive & Motorcycle' => [
            'All-Weather Car Floor Mat Set',
            'Motorcycle Waterproof Raincoat',
            'Portable Tire Inflator 150PSI',
            'LED Headlight Bulb Pair H4',
        ],
        'Furniture and Office Equipment' => [
            'Solid Wood Study Desk',
            'Ergonomic Mesh Office Chair',
            '5-Tier Bookshelf with Steel Frame',
            'Height-Adjustable Laptop Stand',
        ],
        'Jewelry and Watches' => [
            'Sterling Silver Minimalist Necklace',
            'Freshwater Pearl Stud Earrings',
            'Stainless Steel Chronograph Watch',
            'Personalized Initial Ring',
        ],
        'Office and School Supplies' => [
            'A5 Dotted Hardcover Notebook',
            'Fine-Tip Drawing Pen Set of 8',
            'Expandable Document Organizer',
            'Metal Desktop Book Stand',
        ],
    ];

    /**
     * Used when a product has no category (`category_id` is nullable).
     *
     * @var list<string>
     */
    public const FALLBACK_NAMES = [
        'Everyday Marketplace Essential',
        'Verified Seller Best Seller',
        'Zefanya Value Bundle',
        'Limited Local Stock Item',
    ];

    /**
     * Peso price points with realistic endings. `products.price` is
     * decimal(10,2) — NOT the centavo `*_minor` convention of the order tables.
     *
     * @var list<float|int>
     */
    public const PRICE_POINTS = [99, 149.5, 199, 249.95, 349, 499, 649, 899, 1199, 1599, 2199, 2999, 3999, 5499];

    /**
     * ':name' and ':category' are replaced by the factory.
     *
     * @var list<string>
     */
    public const DESCRIPTION_TEMPLATES = [
        'Ships from a verified Zefanya seller. :name is quality-checked before dispatch and packed for safe delivery nationwide.',
        ':category pick from a local shop. :name arrives ready to use, with the seller answering buyer questions in the chat.',
        'A repeat best seller in :category. :name is in stock now and ships within 24 hours of payment confirmation.',
    ];

    /**
     * @return list<string>
     */
    public static function namesFor(?string $categoryLabel): array
    {
        return self::NAMES[$categoryLabel] ?? self::FALLBACK_NAMES;
    }
}

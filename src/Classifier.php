<?php
namespace CompanyFinder;

/**
 * Infers a business category from a listing's title and description.
 *
 * BizBuySell's structured data does not include a category/industry field, so
 * listings would otherwise all be "Uncategorized". This keyword classifier
 * fills that gap. Each category scores by keyword hits (title matches count
 * double); the highest-scoring category wins, falling back to "Uncategorized".
 */
class Classifier
{
    /**
     * Ordered category => keyword list. Order is the tie-breaker, so more
     * specific categories should precede broad ones.
     *
     * @var array<string,array<int,string>>
     */
    private const CATEGORIES = [
        'Automotive'            => ['auto repair', 'automotive', 'car wash', 'collision', 'body shop', 'transmission', 'tire', 'mechanic', 'oil change', 'lube', 'muffler', 'auto body', 'detailing'],
        'Home Services'         => ['hvac', 'plumbing', 'plumber', 'electrical', 'electrician', 'roofing', 'roofer', 'landscaping', 'lawn', 'pool service', 'pest control', 'janitorial', 'cleaning service', 'handyman', 'painting', 'garage door', 'fencing', 'irrigation', 'pressure washing'],
        'Construction'          => ['construction', 'contractor', 'concrete', 'remodeling', 'remodeler', 'builder', 'excavation', 'drywall', 'masonry', 'paving', 'framing', 'general contractor'],
        'Manufacturing'         => ['manufacturing', 'machine shop', 'cnc', 'fabrication', 'fabricator', 'factory', 'injection molding', 'millwork', 'cabinet', 'metal works', 'sheet metal', 'tool and die', 'production'],
        'Health & Medical'      => ['physical therapy', 'dental', 'dentist', 'medical', 'clinic', 'chiropractic', 'home health', 'pharmacy', 'optometry', 'urgent care', 'health care', 'healthcare', 'therapy'],
        'Veterinary & Pet'      => ['veterinary', 'vet clinic', 'pet grooming', 'pet store', 'kennel', 'doggy', 'boarding', 'animal hospital', 'pet '],
        'Beauty & Wellness'     => ['salon', 'med spa', 'medspa', ' spa', 'barber', 'nail', 'massage', 'aesthetic', 'tanning', 'lash', 'skincare', 'wellness'],
        'Fitness'               => ['gym', 'fitness', 'yoga', 'pilates', 'crossfit', 'martial arts', 'personal training'],
        'Childcare & Education' => ['daycare', 'day care', 'childcare', 'child care', 'preschool', 'learning center', 'tutoring', 'montessori', 'early learning', 'academy'],
        'E-Commerce'            => ['e-commerce', 'ecommerce', 'online store', 'amazon fba', 'shopify', 'dropship', 'online retailer', 'online business'],
        'IT & Technology'       => ['software', 'saas', 'managed it', 'msp', 'it services', 'technology', 'web design', 'app ', 'cybersecurity', 'data center'],
        'Professional Services' => ['accounting', 'bookkeeping', 'tax practice', 'tax service', 'law firm', 'legal', 'insurance agency', 'consulting', 'marketing agency', 'staffing', 'advertising', 'engineering firm', 'architecture'],
        'Real Estate'           => ['self-storage', 'self storage', 'storage facility', 'property management', 'apartment', 'mobile home park', 'rv park', 'rental property'],
        'Distribution & Wholesale' => ['distribution', 'wholesale', 'logistics', 'freight', 'trucking', 'vending', 'delivery route', 'supplier', 'import'],
        'Food & Beverage'       => ['bakery', 'coffee', 'catering', 'brewery', 'winery', 'food production', 'commissary', 'juice', 'ice cream', 'beverage'],
        'Entertainment'         => ['arcade', 'bowling', 'play center', 'entertainment', 'event venue', 'escape room', 'trampoline'],
        'Retail'                => ['liquor store', 'convenience store', 'smoke shop', 'vape', 'grocery', 'boutique', 'gift shop', 'jewelry', 'flower shop', 'florist', 'retail'],
    ];

    public static function classify(string $title, string $description = ''): string
    {
        $title = strtolower($title);
        $desc  = strtolower($description);

        $best = 'Uncategorized';
        $bestScore = 0;
        foreach (self::CATEGORIES as $category => $keywords) {
            $score = 0;
            foreach ($keywords as $kw) {
                if (str_contains($title, $kw)) {
                    $score += 2;
                } elseif ($desc !== '' && str_contains($desc, $kw)) {
                    $score += 1;
                }
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $category;
            }
        }
        return $best;
    }
}

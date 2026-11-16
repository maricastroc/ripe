<?php

declare(strict_types=1);

return [
    [
        'key' => 'lisbon-trip',
        'prompt' => 'Plan a 4-day trip to Lisbon in December for two people, on a moderate budget. We like food, history and walking.',
        'answers' => [
            'date|when|arriv|leav|depart|which days' => 'We arrive on Friday morning, December 12, and leave on Monday evening.',
            'budget|cost|spend|price|money' => 'Around 1,200 euros for both of us, flights not included.',
            'car|transport|getting around|tram|metro' => 'No car. We are happy to walk a lot and use trams and trains.',
            'stay|accommodation|hotel|lodging|neighbou?rhood' => 'We booked a small flat in Graça.',
            'diet|food|cuisine|eat|restaurant' => 'We eat everything and love seafood.',
        ],
    ],
    [
        'key' => 'late-order-reply',
        'prompt' => 'Reply to a client whose order arrived a week late and who is asking for a refund.',
        'answers' => [
            'name|who is the client|recipient' => 'The client is Ana Ribeiro.',
            'order|reference|number|product|item' => 'Order HC-2047, a set of six hand-glazed mugs, placed on November 3.',
            'refund|compensation|offer|option|credit|discount|amount' => 'Offer a choice: a 15% refund on the order, or free shipping on her next two orders.',
            'why|reason|cause|delay|late' => 'A carrier strike held the parcel for a week. It was not the client\'s fault, and it was not ours to fix in time.',
            'sign|signature|sender|from whom|your name|company|tone' => 'Warm and direct. Sign it as Inês, from the customer care team at Hortelã Ceramics.',
        ],
    ],
];

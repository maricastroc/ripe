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
    [
        'key' => 'lisbon-trip-pt',
        'prompt' => 'Planeje uma viagem de 4 dias para Lisboa em dezembro, para duas pessoas, com orçamento moderado. A gente gosta de comida, história e de caminhar.',
        'answers' => [
            'data|quando|chega|sai|partida|volta|quais dias' => 'Chegamos na sexta de manhã, 12 de dezembro, e vamos embora na segunda à noite.',
            'orçamento|custo|gast|preço|valor|dinheiro' => 'Uns 1.200 euros para nós dois, sem contar as passagens.',
            'carro|transporte|locomo|bonde|elétrico|metrô|metro' => 'Sem carro. Topamos andar bastante e usar bondes e trens.',
            'hosped|hotel|acomoda|ficar|bairro' => 'Reservamos um apartamento pequeno na Graça.',
            'dieta|comida|culinária|comer|restaurante|restrição' => 'A gente come de tudo e adora frutos do mar.',
        ],
    ],
    [
        'key' => 'late-order-reply-pt',
        'prompt' => 'Responder uma cliente cujo pedido chegou uma semana atrasado e que está pedindo reembolso.',
        'answers' => [
            'nome|quem é a cliente|destinat' => 'A cliente é a Ana Ribeiro.',
            'pedido|número|referência|produto|item' => 'Pedido HC-2047, um jogo de seis canecas esmaltadas à mão, feito em 3 de novembro.',
            'reembolso|compensa|ofere|opção|crédito|desconto|valor' => 'Ofereça uma escolha: reembolso de 15% do pedido ou frete grátis nos próximos dois pedidos.',
            'por que|motivo|causa|atraso|atrasou' => 'Uma greve da transportadora segurou o pacote por uma semana. Não foi culpa da cliente, e não estava ao nosso alcance resolver a tempo.',
            'assin|remetente|de quem|seu nome|empresa|tom' => 'Tom caloroso e direto. Assine como Inês, do atendimento da Hortelã Cerâmica.',
        ],
    ],
];

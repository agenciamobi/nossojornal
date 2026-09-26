<?php
declare(strict_types=1);

require __DIR__ . '/_admin.php';

nj_admin_run(['GET'], static function (): array {
    $user = nj_admin_current_user(true);
    nj_admin_require_pautas_owner($user);

    return [
        'owner' => [
            'login' => NJ_PAUTAS_OWNER_LOGIN,
            'userId' => $user['id'],
        ],
        'pipeline' => [
            'RSS',
            'Captura',
            'Mesa de Pautas',
            'Seleção',
            'Pesquisa',
            'Redação',
            'Draft',
            'Revisão',
            'Agendamento',
        ],
        'sources' => [
            [
                'name' => 'Jornal Tradição',
                'category' => 'Pelotas',
                'feedUrl' => 'https://www.jornaltradicao.com.br/pelotas/feed/',
                'kind' => 'jornalística',
                'priority' => 90,
            ],
            [
                'name' => 'Google News: Pelotas',
                'category' => 'Pelotas',
                'feedUrl' => 'https://news.google.com/rss/search?q=Pelotas&hl=pt-BR&gl=BR&ceid=BR:pt-419',
                'kind' => 'agregador',
                'priority' => 70,
            ],
            [
                'name' => 'Tecnoblog',
                'category' => 'Tecnologia BR',
                'feedUrl' => 'https://tecnoblog.net/feed/',
                'kind' => 'jornalística',
                'priority' => 85,
            ],
            [
                'name' => 'TechCrunch',
                'category' => 'Tecnologia',
                'feedUrl' => 'https://techcrunch.com/feed/',
                'kind' => 'jornalística',
                'priority' => 80,
            ],
            [
                'name' => 'The Verge',
                'category' => 'Tecnologia',
                'feedUrl' => 'https://www.theverge.com/rss/index.xml',
                'kind' => 'jornalística',
                'priority' => 80,
            ],
            [
                'name' => 'Ars Technica',
                'category' => 'Tecnologia/Ciência',
                'feedUrl' => 'https://feeds.arstechnica.com/arstechnica/index',
                'kind' => 'jornalística',
                'priority' => 85,
            ],
            [
                'name' => 'Hacker News',
                'category' => 'Radar Tech',
                'feedUrl' => 'https://news.ycombinator.com/rss',
                'kind' => 'radar',
                'priority' => 55,
            ],
            [
                'name' => 'OpenAI News',
                'category' => 'IA',
                'feedUrl' => 'https://openai.com/news/rss.xml',
                'kind' => 'fonte primária',
                'priority' => 100,
            ],
            [
                'name' => 'Google AI',
                'category' => 'IA',
                'feedUrl' => 'https://blog.google/technology/ai/rss/',
                'kind' => 'fonte primária',
                'priority' => 100,
            ],
            [
                'name' => 'Google DeepMind',
                'category' => 'IA',
                'feedUrl' => 'https://deepmind.google/blog/rss.xml',
                'kind' => 'fonte primária',
                'priority' => 100,
            ],
            [
                'name' => 'Hugging Face',
                'category' => 'IA/Open Source',
                'feedUrl' => 'https://huggingface.co/blog/feed.xml',
                'kind' => 'fonte primária',
                'priority' => 90,
            ],
            [
                'name' => 'Mistral AI',
                'category' => 'IA',
                'feedUrl' => 'https://mistral.ai/news/rss',
                'kind' => 'fonte primária',
                'priority' => 90,
            ],
            [
                'name' => 'NASA Science',
                'category' => 'Universo',
                'feedUrl' => 'https://science.nasa.gov/feed/',
                'kind' => 'fonte primária',
                'priority' => 100,
            ],
            [
                'name' => 'ESA Space Science',
                'category' => 'Universo',
                'feedUrl' => 'https://www.esa.int/rssfeed/Our_Activities/Space_Science',
                'kind' => 'fonte primária',
                'priority' => 95,
            ],
            [
                'name' => 'Space.com',
                'category' => 'Universo',
                'feedUrl' => 'https://www.space.com/feeds/all',
                'kind' => 'jornalística',
                'priority' => 80,
            ],
            [
                'name' => 'ScienceDaily: Science',
                'category' => 'Ciência',
                'feedUrl' => 'https://www.sciencedaily.com/rss/top/science.xml',
                'kind' => 'jornalística',
                'priority' => 80,
            ],
            [
                'name' => 'ScienceDaily: Technology',
                'category' => 'Tecnologia Científica',
                'feedUrl' => 'https://www.sciencedaily.com/rss/top/technology.xml',
                'kind' => 'jornalística',
                'priority' => 80,
            ],
            [
                'name' => 'ScienceDaily: Strange & Offbeat',
                'category' => 'Curiosidades',
                'feedUrl' => 'https://www.sciencedaily.com/rss/strange_offbeat.xml',
                'kind' => 'jornalística',
                'priority' => 75,
            ],
            [
                'name' => 'arXiv cs.AI',
                'category' => 'Pesquisa IA',
                'feedUrl' => 'https://rss.arxiv.org/rss/cs.AI',
                'kind' => 'radar',
                'priority' => 65,
            ],
        ],
    ];
});

<?php

return [
            [
                'menu_code' => '90000',
                'menu_name' => '개발 메뉴',
                'menu_icon' => 'WrenchScrewdriverIcon',
                'module_code' => 'Debug',
                'menu_route_name' => '',
                'menu_level' => 0,
                'children' => [
                    [
                        'menu_code' => '90001',
                        'menu_name' => '차트',
                        'menu_icon' => 'ChartBarIcon',
                        'module_code' => 'Debug',
                        'menu_route_name' => '',
                        'menu_level' => 0,
                        'children' => [
                            [
                                'menu_code' => '91001',
                                'menu_name' => 'Bar Chart',
                                'menu_icon' => null,
                                'module_code' => 'Debug',
                                'menu_route_name' => 'chart.bar',
                                'menu_level' => 0
                            ],
                            [
                                'menu_code' => '91002',
                                'menu_name' => 'Line Chart',
                                'menu_icon' => null,
                                'module_code' => 'Debug',
                                'menu_route_name' => 'chart.line',
                                'menu_level' => 0
                            ],
                            [
                                'menu_code' => '91003',
                                'menu_name' => 'Circle Chart',
                                'menu_icon' => null,
                                'module_code' => 'Debug',
                                'menu_route_name' => 'chart.circle',
                                'menu_level' => 0
                            ],
                            [
                                'menu_code' => '91004',
                                'menu_name' => 'Combo Chart',
                                'menu_icon' => null,
                                'module_code' => 'Debug',
                                'menu_route_name' => 'chart.combo',
                                'menu_level' => 0
                            ],
                        ]
                    ],
                    [
                        'menu_code' => '90002',
                        'menu_name' => 'API 테스트',
                        'menu_icon' => 'CodeBracketIcon',
                        'module_code' => 'Debug',
                        'menu_route_name' => 'dev.api',
                        'menu_level' => 0
                    ]
                ]
            ],
            [
                'menu_code' => '0000',
                'menu_name' => '설정',
                'menu_icon' => 'Cog6ToothIcon',
                'module_code' => 'Basic',
                'menu_route_name' => '',
                'menu_level' => 1,
                'children' => [
                    [
                        'menu_code' => '0001',
                        'menu_name' => '시스템 설정',
                        'menu_icon' => null,
                        'module_code' => 'Basic',
                        'menu_route_name' => 'basic.conf',
                        'menu_level' => 0
                    ],
                    [
                        'menu_code' => '0002',
                        'menu_name' => '메뉴관리',
                        'menu_icon' => null,
                        'module_code' => 'Basic',
                        'menu_route_name' => 'basic.menu',
                        'menu_level' => 0
                    ],
                    [
                        'menu_code' => '0003',
                        'menu_name' => '환경설정',
                        'menu_icon' => null,
                        'module_code' => 'Basic',
                        'menu_route_name' => 'basic.pref',
                        'menu_level' => 1,
                    ],
                    [
                        'menu_code' => '0004',
                        'menu_name' => '사용자관리',
                        'menu_icon' => null,
                        'module_code' => 'Basic',
                        'menu_route_name' => 'basic.mem',
                        'menu_level' => 1
                    ],
                    [
                        'menu_code' => '0005',
                        'menu_name' => '사용자권한관리',
                        'menu_icon' => null,
                        'module_code' => 'Basic',
                        'menu_route_name' => 'basic.auth',
                        'menu_level' => 1
                    ],
                    [
                        'menu_code' => '0006',
                        'menu_name' => '코드관리',
                        'menu_icon' => null,
                        'module_code' => 'Basic',
                        'menu_route_name' => 'basic.code',
                        'menu_level' => 0
                    ]
                ],
            ],
            [
                'menu_code' => '1000',
                'menu_name' => '기준정보관리',
                'menu_icon' => 'Cog6ToothIcon',
                'module_code' => 'Config',
                'menu_route_name' => '',
                'menu_level' => 99,
                'children' => []
            ],
            [
                'menu_code' => '2000',
                'menu_name' => '수주관리',
                'menu_icon' => 'ClipboardIcon',
                'module_code' => 'Order',
                'menu_route_name' => '',
                'menu_level' => 99,
                'children' => [
                    [
                        'menu_code' => '2001',
                        'menu_name' => '수주관리',
                        'menu_icon' => null,
                        'module_code' => 'Order',
                        'menu_route_name' => '',
                        'menu_level' => 99,
                    ],
                    [
                        'menu_code' => '2002',
                        'menu_name' => '수주진행관리',
                        'menu_icon' => null,
                        'module_code' => 'Order',
                        'menu_route_name' => '',
                        'menu_level' => 99,
                    ],
                ]
            ],
            [
                'menu_code' => '3000',
                'menu_name' => '자재관리',
                'menu_icon' => 'CircleStackIcon',
                'module_code' => 'Material',
                'menu_route_name' => '',
                'menu_level' => 99,
                'children' => [
                    [
                        'menu_code' => '3001',
                        'menu_name' => '구매관리',
                        'menu_icon' => null,
                        'module_code' => 'Material',
                        'menu_route_name' => '',
                        'menu_level' => 99,
                    ],
                    [
                        'menu_code' => '3002',
                        'menu_name' => '발주서관리',
                        'menu_icon' => null,
                        'module_code' => 'Material',
                        'menu_route_name' => '',
                        'menu_level' => 99,
                    ],
                    [
                        'menu_code' => '3003',
                        'menu_name' => '입고관리',
                        'menu_icon' => null,
                        'module_code' => 'Material',
                        'menu_route_name' => '',
                        'menu_level' => 99,
                    ],
                    [
                        'menu_code' => '3004',
                        'menu_name' => '출고관리',
                        'menu_icon' => null,
                        'module_code' => 'Material',
                        'menu_route_name' => '',
                        'menu_level' => 99,
                    ],
                    [
                        'menu_code' => '3005',
                        'menu_name' => '재고관리',
                        'menu_icon' => null,
                        'module_code' => 'Material',
                        'menu_route_name' => '',
                        'menu_level' => 99,
                    ],
                    [
                        'menu_code' => '3006',
                        'menu_name' => '재고조정',
                        'menu_icon' => null,
                        'module_code' => 'Material',
                        'menu_route_name' => '',
                        'menu_level' => 99,
                    ],
                ]
            ],
            [
                'menu_code' => '4000',
                'menu_name' => '생산관리',
                'menu_icon' => 'ArchiveBoxArrowDownIcon',
                'module_code' => 'Production',
                'menu_route_name' => '',
                'menu_level' => 99,
                'children' => [
                    [
                        'menu_code' => '4001',
                        'menu_name' => '생산계획관리',
                        'menu_icon' => null,
                        'module_code' => 'Production',
                        'menu_route_name' => '',
                        'menu_level' => 99,
                    ],
                    [
                        'menu_code' => '4002',
                        'menu_name' => '공정실적관리',
                        'menu_icon' => null,
                        'module_code' => 'Production',
                        'menu_route_name' => '',
                        'menu_level' => 99,
                    ],
                    [
                        'menu_code' => '4003',
                        'menu_name' => '생산실적현황',
                        'menu_icon' => null,
                        'module_code' => 'Production',
                        'menu_route_name' => '',
                        'menu_level' => 99,
                    ],
                    [
                        'menu_code' => '4004',
                        'menu_name' => '생산일정관리',
                        'menu_icon' => null,
                        'module_code' => 'Production',
                        'menu_route_name' => '',
                        'menu_level' => 99,
                    ],
                    [
                        'menu_code' => '4005',
                        'menu_name' => '외주관리',
                        'menu_icon' => null,
                        'module_code' => 'Production',
                        'menu_route_name' => '',
                        'menu_level' => 99,
                        'children' => [
                            [
                                'menu_code' => '4101',
                                'menu_name' => '외주 출고',
                                'menu_icon' => null,
                                'module_code' => 'Production',
                                'menu_route_name' => '',
                                'menu_level' => 99,
                            ],
                            [
                                'menu_code' => '4102',
                                'menu_name' => '외주 입고',
                                'menu_icon' => null,
                                'module_code' => 'Production',
                                'menu_route_name' => '',
                                'menu_level' => 99,
                            ],
                        ]
                    ],
                ]
            ],
            [
                'menu_code' => '5000',
                'menu_name' => '제품관리',
                'menu_icon' => 'CircleStackIcon',
                'module_code' => 'Product',
                'menu_route_name' => '',
                'menu_level' => 99,
                'children' => [
                    [
                        'menu_code' => '5001',
                        'menu_name' => '입고관리',
                        'menu_icon' => null,
                        'module_code' => 'Product',
                        'menu_route_name' => '',
                        'menu_level' => 99,
                    ],
                    [
                        'menu_code' => '5002',
                        'menu_name' => '출고관리',
                        'menu_icon' => null,
                        'module_code' => 'Product',
                        'menu_route_name' => '',
                        'menu_level' => 99,
                    ],
                    [
                        'menu_code' => '5003',
                        'menu_name' => '재고관리',
                        'menu_icon' => null,
                        'module_code' => 'Product',
                        'menu_route_name' => '',
                        'menu_level' => 99,
                    ],
                    [
                        'menu_code' => '5004',
                        'menu_name' => '재고조정',
                        'menu_icon' => null,
                        'module_code' => 'Product',
                        'menu_route_name' => '',
                        'menu_level' => 99,
                    ],
                ]
            ],
            [
                'menu_code' => '6000',
                'menu_name' => '품질관리',
                'menu_icon' => 'ClipboardDocumentCheckIcon',
                'module_code' => 'Quality',
                'menu_route_name' => '',
                'menu_level' => 99,
                'children' => [
                    [
                        'menu_code' => '6001',
                        'menu_name' => '입고검사',
                        'menu_icon' => null,
                        'module_code' => 'Quality',
                        'menu_route_name' => '',
                        'menu_level' => 99,
                    ],
                    [
                        'menu_code' => '6002',
                        'menu_name' => '공정검사',
                        'menu_icon' => null,
                        'module_code' => 'Quality',
                        'menu_route_name' => '',
                        'menu_level' => 99,
                    ],
                    [
                        'menu_code' => '6003',
                        'menu_name' => '출고검사',
                        'menu_icon' => null,
                        'module_code' => 'Quality',
                        'menu_route_name' => '',
                        'menu_level' => 99,
                    ],
                    [
                        'menu_code' => '6004',
                        'menu_name' => '품목별 불량이력',
                        'menu_icon' => null,
                        'module_code' => 'Quality',
                        'menu_route_name' => '',
                        'menu_level' => 99,
                    ],
                ]
            ],
            [
                'menu_code' => '7000',
                'menu_name' => '출하관리',
                'menu_icon' => 'TruckIcon',
                'module_code' => 'Shipment',
                'menu_route_name' => '',
                'menu_level' => 99,
                'children' => [
                    [
                        'menu_code' => '7001',
                        'menu_name' => '출하관리',
                        'menu_icon' => null,
                        'module_code' => 'Shipment',
                        'menu_route_name' => '',
                        'menu_level' => 99,
                    ],
                    [
                        'menu_code' => '7002',
                        'menu_name' => '출하이력관리',
                        'menu_icon' => null,
                        'module_code' => 'Shipment',
                        'menu_route_name' => '',
                        'menu_level' => 99,
                    ],
                    [
                        'menu_code' => '7003',
                        'menu_name' => '거래명세서',
                        'menu_icon' => null,
                        'module_code' => 'Shipment',
                        'menu_route_name' => '',
                        'menu_level' => 99,
                    ],
                ]
            ],
        ];

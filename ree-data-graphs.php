<?php
/*
Plugin Name: REE Data Graphs
Description: Datos API de REE
Version: 1.6
Author: UPinSERP
*/

// Función para cargar los scripts y estilos para gráficos
function ree_enqueue_assets() {
    wp_enqueue_script('chartjs', 'https://cdn.jsdelivr.net/npm/chart.js', [], null, true);
    wp_enqueue_script('chartjs-adapter-date-fns', 'https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns', [], null, true);
    wp_add_inline_script('chartjs', ree_custom_js());
}
add_action('wp_enqueue_scripts', 'ree_enqueue_assets');

function ree_custom_js() {
    return "
    document.addEventListener('DOMContentLoaded', function () {
        // Código de resaltado de celdas eliminado
    });
    ";
}

// Obtener los datos de la API de REE
function ree_obtener_datos_api($start_date, $end_date, $time_trunc = 'hour') {
    $token = getenv('REE_API_TOKEN');  
    $url = sprintf("https://apidatos.ree.es/es/datos/mercados/precios-mercados-tiempo-real?start_date=%s&end_date=%s&time_trunc=%s", urlencode($start_date), urlencode($end_date), urlencode($time_trunc));
    $options = ['http' => ['header' => "Authorization: Bearer $token\r\n"]];
    $context = stream_context_create($options);
    return file_get_contents($url, false, $context);
}

// Procesar los datos de la API
function ree_procesar_datos($start_date, $end_date, $rango = 'horas') {
    $time_trunc = $rango == 'meses' ? 'month' : 'hour';
    $data = ree_obtener_datos_api($start_date, $end_date, $time_trunc);
    $json_data = json_decode($data, true);
    if (empty($json_data) || !isset($json_data['included'][0]['attributes']['values'])) return null;

    $values = array_map(fn($item) => $item['value'] / 1000, $json_data['included'][0]['attributes']['values']); // Convertir €/MWh a €/kWh
    $dias_semana = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];
    $labels = array_map(function($item) use ($rango, $dias_semana) {
        $datetime = new DateTime($item['datetime']);
        if ($rango == 'dias') {
            return $dias_semana[$datetime->format('w')] . ' ' . $datetime->format('d');
        } elseif ($rango == 'meses') {
            return $datetime->format('M');
        } else {
            return $datetime->format('H') . 'h';
        }
    }, $json_data['included'][0]['attributes']['values']);

    return ['labels' => $labels, 'values' => $values, 'raw_data' => $json_data['included'][0]['attributes']['values']];
}

// Gráfico
function ree_mostrar_grafico($start_date, $end_date, $unique_id, $rango = 'horas') {
    $data = ree_procesar_datos($start_date, $end_date, $rango);
    if (!$data) return 'Hubo un error al cargar los datos.';

    ob_start();
    ?>
    <div class="chart-container">
        <canvas id="precioLuzChart_<?php echo esc_attr($unique_id); ?>" width="400" height="250"></canvas>
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const canvas = document.getElementById('precioLuzChart_<?php echo esc_attr($unique_id); ?>');
            const ctx = canvas.getContext('2d');
            if (canvas.chart) canvas.chart.destroy();

            const data = <?php echo json_encode($data['raw_data']); ?>;
            const labels = <?php echo json_encode($data['labels']); ?>;
            const values = <?php echo json_encode($data['values']); ?>;
            const uniqueLabels = labels.filter((v, i, a) => a.indexOf(v) === i);

            canvas.chart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: uniqueLabels,
                    datasets: [{
                        label: 'Precio (€ / kWh)',
                        data: values,
                        borderColor: 'rgb(75, 192, 192)',
                        backgroundColor: 'rgba(75, 192, 192, 0.1)',
                        borderWidth: 3,
                        pointBackgroundColor: 'rgb(75, 192, 192)',
                        pointRadius: 6,
                        pointHoverRadius: 8,
                        tension: 0.4,
                        fill: true,
                        pointBorderWidth: 2,
                        shadowColor: 'rgba(75, 192, 192, 0.5)',
                        shadowBlur: 10,
                        shadowOffsetX: 4,
                        shadowOffsetY: 4,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        x: {
                            grid: { display: false },
                            ticks: {
                                callback: function(value, index, values) {
                                    return uniqueLabels[index];
                                }
                            }
                        },
                        y: {
                            beginAtZero: true,
                            grid: { color: 'rgba(0, 0, 0, 0.1)' },
                            ticks: { font: { size: 14 } }
                        }
                    },
                    plugins: {
                        legend: {
                            position: 'top',
                            labels: {
                                font: { size: 14, family: 'Arial, sans-serif', color: 'rgb(75, 192, 192)' }
                            }
                        },
                        tooltip: {
                            backgroundColor: 'rgba(75, 192, 192, 0.9)',
                            titleColor: 'white',
                            bodyColor: 'white',
                            bodyFont: { size: 16 },
                            cornerRadius: 5,
                            padding: 10,
                            callbacks: {
                                label: function(tooltipItem) {
                                    return '€' + tooltipItem.raw.toFixed(4);
                                }
                            }
                        }
                    }
                }
            });
        });
    </script>
    <?php
    return ob_get_clean();
}

// Tabla con precios del día
function ree_tabla_precio_dia() {
    $start_date = date('Y-m-d') . 'T00:00';
    $end_date = date('Y-m-d') . 'T23:59';
    return generar_tabla_estilo($start_date, $end_date);
}

// Tabla con precios del día siguiente
function ree_tabla_precio_dia_siguiente() {
    $start_date = date('Y-m-d', strtotime('tomorrow')) . 'T00:00';
    $end_date = date('Y-m-d', strtotime('tomorrow')) . 'T23:59';
    return generar_tabla_estilo($start_date, $end_date);
}

// Generar tablas con estilo
function generar_tabla_estilo($start_date, $end_date) {
    $data = ree_procesar_datos($start_date, $end_date);
    if (!$data) return 'Hubo un error al cargar los datos.';

    $rows = '';
    $hours = array_chunk($data['values'], 6);
    $hour_labels = array_chunk($data['labels'], 6);
    $min_price = min($data['values']);
    $max_price = max($data['values']);
     $color_scale = [
        '#8bc34a', '#9ccc65', '#aed581', '#c5e1a5', '#e6ee9c', '#fff59d',
        '#ffe082', '#ffcc80', '#ffb74d', '#ffa726', '#ff9800', '#fb8c00'
    ];

    foreach ($hours as $row_index => $row_data) {
        $rows .= "<tr>";
        foreach ($hour_labels[$row_index] as $label) {
            $rows .= "<th>$label</th>";
        }
        $rows .= "</tr><tr>";
        foreach ($row_data as $price) {
            $color_index = (int)(($price - $min_price) / ($max_price - $min_price) * (count($color_scale) - 1));
            $color = $color_scale[$color_index];
            $rows .= "<td style='background-color: $color; color: #000000;'>€" . esc_html(number_format($price, 3)) . "</td>";
        }
        $rows .= "</tr>";
    }

    return "<table class='ree-table ree-table-precio-dia' border='1'><thead>$rows</thead></table>";
}

// Tabla comparativa
function ree_tabla_comparativa() {
    $start_date = date('Y-m-d') . 'T00:00';
    $end_date = date('Y-m-d') . 'T23:59';
    return generar_tabla_comparativa($start_date, $end_date);
}

// Generar tablas comparativas
function generar_tabla_comparativa($start_date, $end_date) {
    $data = ree_procesar_datos($start_date, $end_date);
    if (!$data) return 'Hubo un error al cargar los datos.';

    $prices = $data['values'];
    $max_price = max($prices);
    $min_price = min($prices);
    $current_hour = (new DateTime('now', new DateTimeZone('UTC')))->modify('+1 hour')->format('H');
    $current_price = $prices[$current_hour];
    $max_hour = array_search($max_price, $prices);
    $min_hour = array_search($min_price, $prices);
    $max_time = esc_html($data['labels'][$max_hour]);
    $min_time = esc_html($data['labels'][$min_hour]);
    $current_time = esc_html((new DateTime('now', new DateTimeZone('UTC')))->modify('+1 hour')->format('H:i'));  // Display current hour with minutes in UTC+1

    // Calculate colors
     $color_scale = [
        '#8bc34a', '#9ccc65', '#aed581', '#c5e1a5', '#e6ee9c', '#fff59d',
        '#ffe082', '#ffcc80', '#ffb74d', '#ffa726', '#ff9800', '#fb8c00'
    ];

    $max_color_index = (int)(($max_price - $min_price) / ($max_price - $min_price) * (count($color_scale) - 1));
    $current_color_index = (int)(($current_price - $min_price) / ($max_price - $min_price) * (count($color_scale) - 1));
    $min_color_index = (int)(($min_price - $min_price) / ($max_price - $min_price) * (count($color_scale) - 1));
    
    $max_color = $color_scale[$max_color_index];
    $current_color = $color_scale[$current_color_index];
    $min_color = $color_scale[$min_color_index];

    return "
    <table class='ree-table ree-table-comparativa'>
        <thead>
            <tr>
                <th>Precio Máximo</th>
                <th>Precio Actual ($current_time)</th>
                <th>Precio Mínimo</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td style='background-color: $max_color; color: #000000; font-weight: bold;'>€" . esc_html(number_format($max_price, 3)) . "</td>
                <td style='background-color: $current_color; color: #000000; font-weight: bold;'>€" . esc_html(number_format($current_price, 3)) . "</td>
                <td style='background-color: $min_color; color: #000000; font-weight: bold;'>€" . esc_html(number_format($min_price, 3)) . "</td>
            </tr>
        </tbody>
    </table>";
}

// Gráfico del día siguiente (día de mañana)
function ree_grafico_dia_siguiente() {
    $start_date = date('Y-m-d', strtotime('tomorrow')) . 'T00:00';
    $end_date = date('Y-m-d', strtotime('tomorrow')) . 'T23:59';
    $unique_id = uniqid('dia_siguiente_');
    return ree_mostrar_grafico($start_date, $end_date, $unique_id, 'horas');
}

// Gráfico diario
function ree_grafico_dia() {
    $start_date = date('Y-m-d') . 'T00:00';
    $end_date = date('Y-m-d') . 'T23:59';
    $unique_id = uniqid('dia_');
    return ree_mostrar_grafico($start_date, $end_date, $unique_id);
}

// Gráfico de los últimos 7 días
function ree_grafico_7dias() {
    $start_date = date('Y-m-d', strtotime('-6 days')) . 'T00:00';
    $end_date = date('Y-m-d') . 'T23:59';
    $unique_id = uniqid('7dias_');
    return ree_mostrar_grafico($start_date, $end_date, $unique_id, 'dias');
}

// Gráfico mensual
function ree_grafico_mes() {
    $start_date = date('Y-m-01') . 'T00:00';
    $end_date = date('Y-m-t') . 'T23:59';
    $unique_id = uniqid('mes_');
    return ree_mostrar_grafico($start_date, $end_date, $unique_id, 'dias');
}

// Tabla comparativa del día siguiente
function ree_tabla_comparativa_dia_siguiente() {
    $start_date = date('Y-m-d', strtotime('tomorrow')) . 'T00:00';
    $end_date = date('Y-m-d', strtotime('tomorrow')) . 'T23:59';
    return generar_tabla_comparativa($start_date, $end_date);
}

// Shortcodes
add_shortcode('ree_grafico_dia', 'ree_grafico_dia');
add_shortcode('ree_grafico_7dias', 'ree_grafico_7dias');
add_shortcode('ree_grafico_mes', 'ree_grafico_mes');
add_shortcode('ree_grafico_dia_siguiente', 'ree_grafico_dia_siguiente');
add_shortcode('ree_tabla_precio_dia', 'ree_tabla_precio_dia');
add_shortcode('ree_tabla_comparativa', 'ree_tabla_comparativa');
add_shortcode('ree_tabla_precio_dia_siguiente', 'ree_tabla_precio_dia_siguiente');
add_shortcode('ree_tabla_comparativa_dia_siguiente', 'ree_tabla_comparativa_dia_siguiente');
?>

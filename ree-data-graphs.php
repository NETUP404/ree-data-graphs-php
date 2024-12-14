<?php
/*
Plugin Name: REE Data Graphs
Description: Muestra gráficos de precios de electricidad usando la API de REE.
Version: 1.2
Author: UPinSERP.com
*/

// Función para cargar los scripts y estilos necesarios para los gráficos
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

// Función para obtener los datos de la API de REE
function ree_obtener_datos_api($start_date, $end_date) {
    $token = '154cceb69868d78bc9c84debb1126b6416bebf121d70a8d1c3867c4b59553140';  
    $url = sprintf("https://apidatos.ree.es/es/datos/mercados/precios-mercados-tiempo-real?start_date=%s&end_date=%s&time_trunc=hour", urlencode($start_date), urlencode($end_date));
    $options = ['http' => ['header' => "Authorization: Bearer $token\r\n"]];
    $context = stream_context_create($options);
    return file_get_contents($url, false, $context);
}

// Función para procesar los datos de la API
function ree_procesar_datos($start_date, $end_date, $rango = 'horas') {
    $data = ree_obtener_datos_api($start_date, $end_date);
    $json_data = json_decode($data, true);
    if (empty($json_data) || !isset($json_data['included'][0]['attributes']['values'])) return null;

    $values = array_map(fn($item) => $item['value'] / 1000, $json_data['included'][0]['attributes']['values']); // Convertir €/MWh a €/kWh
    $dias_semana = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];
    $labels = array_map(function($item) use ($rango, $dias_semana) {
        $datetime = new DateTime($item['datetime']);
        return $rango == 'dias' ? $dias_semana[$datetime->format('w')] . ' ' . $datetime->format('d') : $datetime->format('H') . 'h';
    }, $json_data['included'][0]['attributes']['values']);

    return ['labels' => $labels, 'values' => $values, 'raw_data' => $json_data['included'][0]['attributes']['values']];
}

// Función para mostrar el gráfico
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
                            cornerRadius: 5,
                            padding: 10,
                            callbacks: {
                                title: function(tooltipItem) {
                                    const date = new Date(data[tooltipItem[0].dataIndex].datetime);
                                    return date.toLocaleDateString('es-ES', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric', hour: 'numeric' });
                                },
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

// Función para mostrar la tabla con precios del día
function ree_tabla_precio_dia() {
    $start_date = date('Y-m-d') . 'T00:00';
    $end_date = date('Y-m-d') . 'T23:59';
    return generar_tabla_estilo($start_date, $end_date);
}

// Función para mostrar la tabla con precios del día siguiente
function ree_tabla_precio_dia_siguiente() {
    $start_date = date('Y-m-d', strtotime('tomorrow')) . 'T00:00';
    $end_date = date('Y-m-d', strtotime('tomorrow')) . 'T23:59';
    return generar_tabla_estilo($start_date, $end_date);
}

// Función genérica para generar tablas con estilo
function generar_tabla_estilo($start_date, $end_date) {
    $data = ree_procesar_datos($start_date, $end_date);
    if (!$data) return 'Hubo un error al cargar los datos.';

    $rows = '';
    $hours = array_chunk($data['values'], 6);
    $hour_labels = array_chunk($data['labels'], 6);
    $min_price = min($data['values']);
    $max_price = max($data['values']);
    $color_scale = [
        '#00cc00', '#33cc33', '#66cc66', '#99cc00', '#cccc00', '#ffcc00',
        '#ffcc66', '#ff9966', '#ff9933', '#ff6600', '#ff6633', '#cc6600'
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

// Función para mostrar la tabla comparativa
function ree_tabla_comparativa() {
    $start_date = date('Y-m-d') . 'T00:00';
    $end_date = date('Y-m-d') . 'T23:59';
    return generar_tabla_comparativa($start_date, $end_date);
}

// Función genérica para generar tablas comparativas
function generar_tabla_comparativa($start_date, $end_date) {
    $data = ree_procesar_datos($start_date, $end_date);
    if (!$data) return 'Hubo un error al cargar los datos.';

    $prices = $data['values'];
    $max_price = max($prices);
    $min_price = min($prices);
    $current_hour = (int)date('H');
    $current_price = $prices[$current_hour];
    $max_hour = array_search($max_price, $prices);
    $min_hour = array_search($min_price, $prices);
    $max_time = esc_html($data['labels'][$max_hour]);
    $min_time = esc_html($data['labels'][$min_hour]);
    $current_time = esc_html($data['labels'][$current_hour]);

    // Calculate colors for max, current, and min prices
    $color_scale = [
        '#00cc00', '#33cc33', '#66cc66', '#99cc00', '#cccc00', '#ffcc00',
        '#ffcc66', '#ff9966', '#ff9933', '#ff6600', '#ff6633', '#cc6600'
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
                <th>Precio Actual</th>
                <th>Precio Mínimo</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td style='background-color: $max_color; color: #000000;'>€" . esc_html(number_format($max_price, 3)) . " ($max_time)</td>
                <td style='background-color: $current_color; color: #000000;'>€" . esc_html(number_format($current_price, 3)) . " ($current_time)</td>
                <td style='background-color: $min_color; color: #000000;'>€" . esc_html(number_format($min_price, 3)) . " ($min_time)</td>
            </tr>
        </tbody>
    </table>";
}

// Función para el gráfico del día siguiente (día de mañana)
function ree_grafico_dia_siguiente() {
    $start_date = date('Y-m-d', strtotime('tomorrow')) . 'T00:00';
    $end_date = date('Y-m-d', strtotime('tomorrow')) . 'T23:59';
    $unique_id = uniqid('dia_siguiente_');
    return ree_mostrar_grafico($start_date, $end_date, $unique_id, 'horas');
}

// Función para el gráfico diario
function ree_grafico_dia() {
    $start_date = date('Y-m-d') . 'T00:00';
    $end_date = date('Y-m-d') . 'T23:59';
    $unique_id = uniqid('dia_');
    return ree_mostrar_grafico($start_date, $end_date, $unique_id);
}

// Función para el gráfico semanal
function ree_grafico_semana() {
    $start_date = date('Y-m-d', strtotime('last Monday')) . 'T00:00';
    $end_date = date('Y-m-d') . 'T23:59';
    $unique_id = uniqid('semana_');
    return ree_mostrar_grafico($start_date, $end_date, $unique_id, 'dias');
}

// Función para el gráfico mensual
function ree_grafico_mes() {
    $start_date = date('Y-m-01') . 'T00:00';
    $end_date = date('Y-m-t') . 'T23:59';
    $unique_id = uniqid('mes_');
    return ree_mostrar_grafico($start_date, $end_date, $unique_id, 'dias');
}

// Función para el gráfico anual
function ree_grafico_anio() {
    $start_date = date('Y-01-01') . 'T00:00';
    $end_date = date('Y-12-31') . 'T23:59';
    $unique_id = uniqid('anio_');
    return ree_mostrar_grafico($start_date, $end_date, $unique_id, 'meses');
}

// Función para mostrar la tabla comparativa del día siguiente
function ree_tabla_comparativa_dia_siguiente() {
    $start_date = date('Y-m-d', strtotime('tomorrow')) . 'T00:00';
    $end_date = date('Y-m-d', strtotime('tomorrow')) . 'T23:59';
    return generar_tabla_comparativa($start_date, $end_date);
}

// Registrar los shortcodes
add_shortcode('ree_grafico_dia', 'ree_grafico_dia');
add_shortcode('ree_grafico_semana', 'ree_grafico_semana');
add_shortcode('ree_grafico_mes', 'ree_grafico_mes');
add_shortcode('ree_grafico_anio', 'ree_grafico_anio');
add_shortcode('ree_grafico_dia_siguiente', 'ree_grafico_dia_siguiente');
add_shortcode('ree_tabla_precio_dia', 'ree_tabla_precio_dia');
add_shortcode('ree_tabla_comparativa', 'ree_tabla_comparativa');
add_shortcode('ree_tabla_precio_dia_siguiente', 'ree_tabla_precio_dia_siguiente');
add_shortcode('ree_tabla_comparativa_dia_siguiente', 'ree_tabla_comparativa_dia_siguiente');
?>

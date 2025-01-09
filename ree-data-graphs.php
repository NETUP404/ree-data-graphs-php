<?php
/*
Plugin Name: REE Data Graphs
Description: Datos API de REE
Version: 2.1
Author: UPinSERP
*/

$config = require __DIR__ . '/config.php';

// Función para cargar los scripts y estilos para gráficos
function ree_enqueue_assets() {
    wp_enqueue_script('chartjs', 'https://cdn.jsdelivr.net/npm/chart.js', [], null, true);
    wp_enqueue_script('chartjs-adapter-date-fns', 'https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns', [], null, true);
    wp_enqueue_script('chartjs-plugin-annotation', 'https://cdn.jsdelivr.net/npm/chartjs-plugin-annotation', [], null, true);
    wp_add_inline_script('chartjs', ree_custom_js());
}
add_action('wp_enqueue_scripts', 'ree_enqueue_assets');

function ree_custom_js() {
    return "
    document.addEventListener('DOMContentLoaded', function () {
        console.log('Chart.js scripts loaded');
    });
    ";
}

// Función para conectar a la base de datos optimizada
function ree_db_connect_optimized() {
    global $config;

    // Cargar credenciales desde la configuración
    $servername = $config['db']['servername'];
    $username = $config['db']['username'];
    $password = $config['db']['password'];
    $dbname = $config['db']['dbname'];

    $conn = new mysqli($servername, $username, $password, $dbname);

    if ($conn->connect_error) {
        die("Conexión fallida: " . $conn->connect_error);
    }
    return $conn;
}

// Obtener los datos de la API de REE y almacenarlos en la base de datos optimizada
function ree_obtener_datos_api_optimized($dia_siguiente = false) {
    global $config;
    $conn = ree_db_connect_optimized();
    $table_name = 'ree_data';

    // Verificar si ya tenemos los datos almacenados
    $date_condition = $dia_siguiente ? "CURDATE() + INTERVAL 1 DAY" : "CURDATE()";
    $stmt = $conn->prepare("SELECT value, datetime FROM $table_name WHERE DATE(timestamp) = $date_condition");
    $stmt->execute();
    $stmt->bind_result($value, $datetime);
    $result = [];
    while ($stmt->fetch()) {
        $result[] = ['value' => $value, 'datetime' => $datetime];
    }
    $stmt->close();

    if (!empty($result)) {
        $conn->close();
        return $result;
    }

    // Si no tenemos los datos, obtenerlos de la API
    if (!isset($config['api']['ree_token'])) {
        die("Token de API no configurado.");
    }
    $token = $config['api']['ree_token'];
    $startDate = (new DateTime($dia_siguiente ? 'tomorrow' : 'today'))->format('Y-m-d');
    $endDate = $startDate;

    $url = "https://api.esios.ree.es/indicators/1001?start_date={$startDate}&end_date={$endDate}";
    $options = ['http' => ['header' => "Authorization: Bearer $token\r\n"]];
    $context = stream_context_create($options);
    $data = file_get_contents($url, false, $context);

    if ($data !== false) {
        $json_data = json_decode($data, true);
        foreach ($json_data['indicator']['values'] as $value) {
            if ($value['geo_name'] === 'Península') {
                $stmt = $conn->prepare("INSERT INTO $table_name (value, datetime) VALUES (?, ?)");
                $stmt->bind_param("ds", $value['value'], $value['datetime']);
                $stmt->execute();
            }
        }
        $stmt->close();
    }

    $conn->close();
    return $result;
}

// Procesar los datos de la API optimizada
function ree_procesar_datos_optimized($dia_siguiente = false) {
    $data = ree_obtener_datos_api_optimized($dia_siguiente);

    if (empty($data)) return null;

    // Conversión de €/MWh a €/kWh
    $values = array_map(fn($item) => $item['value'] / 1000, $data);
    $labels = array_map(function($item) {
        $datetime = new DateTime($item['datetime']);
        return $datetime->format('H:i');
    }, $data);

    return ['labels' => array_values($labels), 'values' => array_values($values)];
}

// Función para mostrar gráfico
function ree_mostrar_grafico($unique_id, $data) {
    if (!$data) return 'Hubo un error al cargar los datos.';

    ob_start();
    ?>
    <div class="chart-container">
        <canvas id="precioLuzChart_<?php echo esc_attr($unique_id); ?>" width="400" height="250"></canvas>
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            console.log('Chart.js initialized for canvas ID: precioLuzChart_<?php echo esc_attr($unique_id); ?>');
            const canvas = document.getElementById('precioLuzChart_<?php echo esc_attr($unique_id); ?>');
            const ctx = canvas.getContext('2d');
            if (canvas.chart) canvas.chart.destroy();

            const labels = <?php echo json_encode(array_values($data['labels'])); ?>;
            const values = <?php echo json_encode(array_values($data['values'])); ?>;
            console.log('Labels:', labels);
            console.log('Values:', values);

            canvas.chart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
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
                                    return labels[index];
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
    return generar_tabla_estilo();
}

// Tabla con precios del día siguiente
function ree_tabla_precio_dia_siguiente() {
    return generar_tabla_estilo(true);
}

// Generar tablas con estilo
function generar_tabla_estilo($dia_siguiente = false) {
    $data = ree_procesar_datos_optimized($dia_siguiente);
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
    return generar_tabla_comparativa();
}

// Generar tablas comparativas
function generar_tabla_comparativa($dia_siguiente = false) {
    $data = ree_procesar_datos_optimized($dia_siguiente);
    if (!$data) return 'Hubo un error al cargar los datos.';

    $prices = $data['values'];
    $max_price = max($prices);
    $min_price = min($prices);
    $current_hour = (new DateTime('now', new DateTimeZone('Europe/Madrid')))->format('H');
    $current_price = $prices[$current_hour] ?? $prices[count($prices) - 1]; // Obtener el precio actual o el último precio disponible
    $max_hour = array_search($max_price, $prices);
    $min_hour = array_search($min_price, $prices);
    $max_time = esc_html($data['labels'][$max_hour]);
    $min_time = esc_html($data['labels'][$min_hour]);
    $current_time = esc_html((new DateTime('now', new DateTimeZone('Europe/Madrid')))->format('H:i'));

    // Calcular colores
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
    $data = ree_procesar_datos_optimized(true);
    $unique_id = uniqid('dia_siguiente_');
    return ree_mostrar_grafico($unique_id, $data);
}

// Gráfico diario
function ree_grafico_dia() {
    $data = ree_procesar_datos_optimized();
    $unique_id = uniqid('dia_');
    return ree_mostrar_grafico($unique_id, $data);
}

// Gráfico de los últimos 7 días
function ree_grafico_7dias() {
    $data = ree_procesar_datos_optimized();
    $unique_id = uniqid('7dias_');
    return ree_mostrar_grafico($unique_id, $data);
}

// Gráfico mensual
function ree_grafico_mes() {
    $data = ree_procesar_datos_optimized();
    $unique_id = uniqid('mes_');
    return ree_mostrar_grafico($unique_id, $data);
}

// Tabla comparativa del día siguiente
function ree_tabla_comparativa_dia_siguiente() {
    return generar_tabla_comparativa(true);
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

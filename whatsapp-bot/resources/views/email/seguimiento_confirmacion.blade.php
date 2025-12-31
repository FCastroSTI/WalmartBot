<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Confirmación Seguimiento</title>
</head>

<body>
    <p>
        Estimado proveedor {{ $nombre_proveedor }} {{ $rut_proveedor }},
    </p>

    <p>
        Agradecemos su confirmación.<br>
        Se realizará un nuevo seguimiento el
        <strong>
            {{ \Carbon\Carbon::parse($fecha_hora)->format('d-m-Y') }}
            a las
            {{ \Carbon\Carbon::parse($fecha_hora)->format('H:i') }} hrs
        </strong>.
    </p>

    <p>
        Atte.<br>
        Walmart Mantención tiendas
    </p>
</body>

</html>
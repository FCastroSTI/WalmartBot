<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Confirmación Seguimiento</title>
</head>

<body>
    <p>
        Se informa que el ticket [ {{ $id_atencion ?? '[ID_Atencion]' }} ]<br>
        Asignado al proveedor {{ $nombre_proveedor ?? '[Nombre Proveedor]' }} {{ $rut_proveedor ?? '[RUT proveedor]' }}.
    </p>

    <p>
        Ha sido reagendado para un nuevo seguimiento el cual se ejecutará en la siguiente fecha y hora:<br>
        <strong>
            {{ $fecha_hora ?? '[Fecha reagendada]' }}
        </strong>
        Actualizar nueva hora y fecha de llegada del proveedor en CRM
    </p>

    <p>
        Atte.<br>
        Walmart Mantención tiendas
    </p>
</body>

</html>
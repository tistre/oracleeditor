<?php
/*
	OracleEditor.php Command Line Interface
	Version 1.20
	by Tim Strehle <tim@strehle.de>

	https://github.com/tistre/oracleeditor

    Usage:

    php OracleCLI.php \
        --user=me \
        --password=secret \
        --service='oracle.example.com:1721/DB' \
        --limit=10 \
        --format=xml \
        --query="select * from DUAL"
*/

// Don't write PHP warnings into HTML. Watch your PHP error_log file!

ini_set('display_errors', 0);

// Format version string

$version = trim(substr('$Revision: 1.20 $', 10, -1));

// Read command line options

$defaults = [
    'format' => 'xml',
    'limit' => 100,
    'page' => 1
];

$options = getopt('', ['user:', 'password:', 'service:', 'format:', 'query:', 'limit:', 'page:']);

$requiredOptions = ['user', 'password', 'service', 'query'];

foreach ($requiredOptions as $option) {
    if (!isset($options[$option])) {
        fwrite(STDERR, sprintf("ERROR: --%s is required.\n", $option));
        exit(1);
    }
}

$options = array_merge($defaults, $options);

if (!is_numeric($options['limit'])) {
    fwrite(STDERR, "ERROR: --limit must be a number.\n");
    exit(1);
}

if (!is_numeric($options['page'])) {
    fwrite(STDERR, "ERROR: --page must be a number.\n");
    exit(1);
}

$exportformats = [
    'xml' => ['XML', 'text/xml'],
    'csv' => ['CSV', 'text/comma-separated-values'],
    'html' => ['HTML table', 'text/html']
];

if (!isset($exportformats[$options['format']])) {
    fwrite(STDERR, sprintf("ERROR: --format must be one of %s.\n", implode(', ', array_keys($exportformats))));
    exit(1);
}

// Initialize database connection parameters

if ($options['user'] !== substr(preg_replace('/[^a-zA-Z0-9$_-]/', '',
        $options['user']), 0, 30)) {
    fwrite(STDERR, "ERROR: --user contains invalid characters.\n");
    exit(1);
}

if ($options['service'] !== substr(preg_replace('|[^a-zA-Z0-9:.() =/_-]|', '',
        $options['service']), 0, 2000)) {
    fwrite(STDERR, "ERROR: --service contains invalid characters.\n");
    exit(1);
}

$charset = 'UTF-8';

// Initialize connection

$conn = false;

pof_connect();

// Exporting may take a while

set_time_limit(0);

// Initialize export settings

$exportlimit = abs(intval($options['limit']));

// Loop through results

$cursor = pof_opencursor($options['query']);

if ($cursor) {
    if (ocistatementtype($cursor) !== 'SELECT') {
        pof_closecursor($cursor);
        fwrite(STDERR, "ERROR: --query must be a SELECT statement.\n");
        exit(1);
    }
}

$columns = [];
$numcols = ocinumcols($cursor);

for ($j = 1; $j <= $numcols; $j++) {
    if (ocicolumnname($cursor, $j) != 'ROWID_') {
        $columns[(ocicolumnname($cursor, $j))] = array(
            'type' => ocicolumntype($cursor, $j),
            'size' => ocicolumnsize($cursor, $j)
        );
    }
}

// Skip previous sets

$rowOffset = 0;

if ($options['page'] > 1) {
    $rowOffset = ($options['page'] - 1) * $options['limit'];
    for ($j = 1; $j <= $rowOffset; $j++) {
        if (!ocifetch($cursor)) {
            break;
        }
    }
}

// Header

if ($options['format'] == 'xml') {
    echo sprintf('<' . '?xml version="1.0" encoding="%s"?' . '>', $charset) . "\n";
    echo "<!-- Generated by OracleEditor.php (https://github.com/tistre/oracleeditor) -->\n";

    $userstr = $options['user'];
    $userstr .= '@' . $options['service'];

    echo sprintf('<rowset exported="%s" user="%s" server="%s">', date('Y-m-d\TH:i:s'), htmlspecialchars($userstr),
            htmlspecialchars(php_uname('n'))) . "\n";
    echo sprintf("\t<sql limit=\"%d\" page=\"%d\">%s</sql>\n", $options['limit'], $options['page'],
        htmlspecialchars($options['query']));

    // Column aliases: We can use column names as tag names only if
    // they're valid XML names - <count(MYFIELD)> won't work.

    $i = 0;
    foreach ($columns as $name => $column) {
        $i++;

        if (preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*$/', $name) == 0) {
            $columns[$name]['alias'] = 'ALIAS' . $i;
        }
    }

    echo "\t<columns>\n";
    foreach ($columns as $name => $column) {
        echo sprintf("\t\t" . '<column name="%s" type="%s" size="%s"%s/>' . "\n",
            htmlspecialchars($name),
            $column['type'],
            $column['size'],
            (isset($column['alias']) ? ' alias="' . $column['alias'] . '"' : '')
        );
    }
    echo "\t</columns>\n";
} elseif ($options['format'] == 'csv') {
    $first = true;

    foreach ($columns as $name => $column) {
        if ($name != 'ROWID_') {
            if (!$first) {
                echo ', ';
            }
            echo sprintf('"%s"', str_replace('"', '""', $name));
            $first = false;
        }
    }

    echo "\n";
} elseif ($options['format'] == 'html') { ?>

    <html>
    <head>
        <meta http-equiv="content-type" content="text/html; charset=<?php echo $charset; ?>">
        <meta name="date" content="<?php echo date('Y-m-d\TH:i:s'); ?>">
        <meta name="generator" content="OracleEditor.php (https://github.com/tistre/oracleeditor)">
        <title>Exported Oracle data (by OracleEditor.php)</title>
    </head>
    <body>

    <h1>Exported Oracle data</h1>

    <?php
    $userstr = $options['user'];
    $userstr .= '@' . $options['service'];
    ?>

    <p>The Oracle user <em><?php echo htmlspecialchars($userstr); ?></em> exported this data on
        <em><?php echo date('r'); ?></em>
        by running the following SQL statement in OracleCLI.php:<br/>
    <pre><?php echo htmlspecialchars($options['query']); ?></pre></p>

    <table border="1">
    <tr>

        <?php

        foreach ($columns as $name => $column) {
            echo sprintf('<th>%s<br />(%s, %s)</th>' . "\n",
                htmlspecialchars($name),
                $column['type'],
                $column['size']
            );
        }

        ?>

    </tr>

    <?php
}

// Rows

$i = 1;

while (true) {
    if (!ocifetchinto($cursor, $row, OCI_ASSOC | OCI_RETURN_LOBS)) {
        break;
    }

    if ($options['format'] == 'xml') {
        echo sprintf("\t<row num=\"%d\"%s>\n",
            ($i + $rowOffset),
            (isset($row['ROWID_']) ? (' id="' . htmlspecialchars($row['ROWID_']) . '"') : ''));

        foreach ($row as $fieldname => $value) {
            if ($fieldname != 'ROWID_') {
                echo sprintf("\t\t<%1\$s>%2\$s</%1\$s>\n",
                    (isset($columns[$fieldname]['alias']) ? $columns[$fieldname]['alias'] : $fieldname),
                    htmlspecialchars($value));
            }
        }

        echo "\t</row>\n";
    } elseif ($options['format'] == 'csv') {
        $first = true;

        foreach ($columns as $fieldname => $column) {
            if ($fieldname != 'ROWID_') {
                if (!$first) {
                    echo ', ';
                }
                if (isset($row[$fieldname])) {
                    echo sprintf('"%s"', str_replace('"', '""', $row[$fieldname]));
                } else {
                    echo '""';
                }
                $first = false;
            }
        }

        echo "\n";
    } elseif ($options['format'] == 'html') {
        echo "<tr>\n";

        foreach ($columns as $fieldname => $column) {
            if ($fieldname != 'ROWID_') {
                echo "\t<td>";
                if (isset($row[$fieldname])) {
                    echo htmlspecialchars($row[$fieldname]);
                }
                echo "</td>\n";
            }
        }

        echo "</tr>\n";
    }

    if (($exportlimit > 0) && ($exportlimit < ++$i)) {
        break;
    }
}

// Footer

if ($options['format'] == 'xml') {
    echo "</rowset>\n";
} elseif ($options['format'] == 'html') { ?>

    </table>
    <p>HTML generated by <a
                href="https://github.com/tistre/oracleeditor">OracleEditor.php</a> <?php echo $version; ?>
        &copy; 2017 by <a href="https://www.strehle.de/tim/">Tim Strehle</a> &lt;<a
                href="mailto:tim@strehle.de">tim@strehle.de</a>&gt;</p>
    </body>
    </html>

    <?php
}

pof_closecursor($cursor);


function pof_connect()
{
    global $conn, $options;

    $conn = oci_connect
    (
        $options['user'],
        $options['password'],
        $options['service'],
        'AL32UTF8'
    );

    $err = ocierror();

    if (is_array($err)) {
        fwrite(STDERR, sprintf("ERROR: Logon failed: %s\n", $err['message']));
        exit(1);
    }
}


function pof_disconnect()
{
    global $conn;

    if ($conn) {
        ocilogoff($conn);
    }
}


function pof_opencursor($sql, $bind = false)
{
    global $conn, $options;

    $cursor = ociparse($conn, $sql);

    if (!$cursor) {
        $err = ocierror($conn);
        if (is_array($err)) {
            fwrite(STDERR, sprintf("ERROR: Parse failed: %s\n", $err['message']));
            exit(1);
        }
    } else { // This might improve performance?
        ocisetprefetch($cursor, $options['limit']);

        if (is_array($bind)) {
            foreach ($bind as $fieldname => $value) {
                ocibindbyname($cursor, ':' . $fieldname, $bind[$fieldname], -1);
            }
        }

        $ok = ociexecute($cursor);

        if (!$ok) {
            $err = ocierror($cursor);

            if (is_array($err)) {
                fwrite(STDERR, sprintf("ERROR: Execute failed: %s\n", $err['message']));
                exit(1);
            }

            pof_closecursor($cursor);

            $cursor = false;
        }
    }

    return $cursor;
}


function pof_closecursor($cursor)
{
    if ($cursor) {
        ocifreestatement($cursor);
    }
}

<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>Page Title</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" type="text/css" media="screen" href="main.css">
    <script src="main.js"></script>

   
    <script src="https://code.highcharts.com/highcharts.js"></script>
    <script src="https://code.highcharts.com/highcharts-3d.js"></script>
    <script src="https://code.highcharts.com/modules/exporting.js"></script>
    <script src="https://code.highcharts.com/modules/export-data.js"></script>

    <style type="text/css">
    #container {
    height: 400px; 
    min-width: 310px; 
    max-width: 800px;
    margin: 0 auto;
    }    
    </style>
</head>
<body>
<div id="container" style="height: 400px"></div>
</body>
<script>

Highcharts.chart('container', {
    chart: {
        type: 'column',
        options3d: {
            enabled: true,
            alpha: 15,
            beta: 15,
            viewDistance: 25,
            depth: 40
        }
    },

    title: {
        text: '用電量比較圖 CHP#1,2,3'
    },

    xAxis: {
        categories: ['9月', '10月', '11月'],
        labels: {
            skew3d: true,
            style: {
                fontSize: '16px'
            }
        }
    },

    yAxis: {
        allowDecimals: false,
        min: 0,
        title: {
            text: '用電量',
            skew3d: true
        }
    },

    tooltip: {
        shared:true,
        headerFormat: '<b>{point.key}</b><br>',
        pointFormat: '<span style="color:{series.color}">\u25CF</span> {series.name}: {point.y} <br />'
    },

    plotOptions: {
        column: {
            stacking: 'normal',
            depth: 40
        },
        series: {
            dataLabels: {
                enabled: true,
            }
        }
    },

    series: [{
        name: '2016年',
        data: [71772, 68694, 74112],
        stack: 'two_year_ago'
    },{
        name: '2017年',
        data: [62772, 64694, 66112],
        stack: 'last_year'
    }, /* {
        name: 'Joe',
        data: [3, 4, 4, 2, 5],
        stack: 'male'
    }, */ {
        name: '2018年',
        data: [45816, 47826, 53937],
        stack: 'this_year'
    },/*  {
        name: 'Janet',
        data: [3, 0, 4, 4, 3],
        stack: 'female'
    } */]
});
</script>
</html>
<!DOCTYPE html>
<html>
<head>
    <title>Interactive Map Drawer - Kibabii University</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="https://unpkg.com/leaflet-draw@1.0.4/dist/leaflet.draw.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://unpkg.com/leaflet-draw@1.0.4/dist/leaflet.draw.js"></script>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 20px;
            background: #f5f5f5;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        h1 {
            color: #2c3e50;
            margin-bottom: 10px;
        }
        
        .subtitle {
            color: #7f8c8d;
            margin-bottom: 20px;
        }
        
        .map-container {
            display: grid;
            grid-template-columns: 1fr 350px;
            gap: 20px;
            height: 600px;
        }
        
        #map {
            height: 100%;
            border-radius: 8px;
            border: 2px solid #3498db;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }
        
        .coordinates-panel {
            background: #ecf0f1;
            padding: 15px;
            border-radius: 8px;
            overflow-y: auto;
            max-height: 600px;
        }
        
        .panel-section {
            background: white;
            padding: 15px;
            margin-bottom: 15px;
            border-radius: 6px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .panel-section h3 {
            margin-top: 0;
            color: #2c3e50;
            border-bottom: 2px solid #3498db;
            padding-bottom: 8px;
        }
        
        .coordinate-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .coordinate-item {
            background: #f8f9fa;
            padding: 10px;
            margin-bottom: 8px;
            border-radius: 4px;
            font-family: monospace;
            font-size: 12px;
            border-left: 3px solid #3498db;
        }
        
        .coord-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 5px;
            font-weight: bold;
            color: #2c3e50;
        }
        
        .coord-actions {
            display: flex;
            gap: 5px;
        }
        
        .btn {
            padding: 5px 10px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            transition: all 0.3s;
        }
        
        .btn-sm {
            padding: 3px 6px;
            font-size: 11px;
        }
        
        .btn-primary {
            background: #3498db;
            color: white;
        }
        
        .btn-primary:hover {
            background: #2980b9;
        }
        
        .btn-success {
            background: #27ae60;
            color: white;
        }
        
        .btn-success:hover {
            background: #229954;
        }
        
        .btn-danger {
            background: #e74c3c;
            color: white;
        }
        
        .btn-danger:hover {
            background: #c0392b;
        }
        
        .btn-warning {
            background: #f39c12;
            color: white;
        }
        
        .btn-warning:hover {
            background: #e67e22;
        }
        
        .btn-secondary {
            background: #95a5a6;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #7f8c8d;
        }
        
        .toolbar {
            margin-bottom: 15px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .stats {
            background: #2c3e50;
            color: white;
            padding: 10px;
            border-radius: 4px;
            margin-top: 10px;
        }
        
        .stat-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 5px;
        }
        
        .format-toggle {
            display: flex;
            gap: 10px;
            margin: 10px 0;
        }
        
        .format-btn {
            flex: 1;
            padding: 8px;
            background: #bdc3c7;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        
        .format-btn.active {
            background: #3498db;
        }
        
        .export-box {
            background: #2c3e50;
            color: white;
            padding: 10px;
            border-radius: 4px;
            font-family: monospace;
            font-size: 12px;
            white-space: pre-wrap;
            word-wrap: break-word;
            max-height: 200px;
            overflow-y: auto;
        }
        
        .coord-input {
            width: 100%;
            padding: 8px;
            margin-bottom: 10px;
            border: 1px solid #bdc3c7;
            border-radius: 4px;
            box-sizing: border-box;
        }
        
        .coord-input:focus {
            outline: none;
            border-color: #3498db;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>📍 Interactive Map Drawer - Kibabii University</h1>
        <div class="subtitle">Draw shapes and record coordinates | Base: 0.61791°N, 34.52427°E</div>
        
        <div class="toolbar">
            <button class="btn btn-primary" onclick="enableDrawMode('marker')">
                <i class="fas fa-map-marker"></i> Add Marker
            </button>
            <button class="btn btn-primary" onclick="enableDrawMode('polyline')">
                <i class="fas fa-slash"></i> Draw Line
            </button>
            <button class="btn btn-primary" onclick="enableDrawMode('polygon')">
                <i class="fas fa-draw-polygon"></i> Draw Polygon
            </button>
            <button class="btn btn-primary" onclick="enableDrawMode('rectangle')">
                <i class="fas fa-square"></i> Draw Rectangle
            </button>
            <button class="btn btn-primary" onclick="enableDrawMode('circle')">
                <i class="fas fa-circle"></i> Draw Circle
            </button>
            <button class="btn btn-success" onclick="clearAll()">
                <i class="fas fa-trash"></i> Clear All
            </button>
            <button class="btn btn-warning" onclick="centerOnUniversity()">
                <i class="fas fa-crosshairs"></i> Center on University
            </button>
        </div>
        
        <div class="map-container">
            <div id="map"></div>
            
            <div class="coordinates-panel">
                <div class="panel-section">
                    <h3>📋 Drawing Tools</h3>
                    <p style="font-size: 12px; color: #7f8c8d;">
                        1. Click a tool above to start drawing<br>
                        2. Click on map to add points<br>
                        3. Double-click to finish drawing<br>
                        4. Click on shapes to edit/delete
                    </p>
                </div>
                
                <div class="panel-section">
                    <h3>📍 Recorded Coordinates</h3>
                    <div class="format-toggle">
                        <button class="format-btn active" onclick="setFormat('decimal')" id="format-decimal">Decimal</button>
                        <button class="format-btn" onclick="setFormat('dms')" id="format-dms">DMS</button>
                    </div>
                    <div id="coordinatesList" class="coordinate-list">
                        <p style="color: #7f8c8d; text-align: center;">No coordinates recorded yet</p>
                    </div>
                </div>
                
                <div class="panel-section">
                    <h3>📊 Statistics</h3>
                    <div id="stats" class="stats">
                        <div class="stat-item">
                            <span>Markers:</span>
                            <span id="stat-markers">0</span>
                        </div>
                        <div class="stat-item">
                            <span>Lines:</span>
                            <span id="stat-lines">0</span>
                        </div>
                        <div class="stat-item">
                            <span>Polygons:</span>
                            <span id="stat-polygons">0</span>
                        </div>
                        <div class="stat-item">
                            <span>Total Points:</span>
                            <span id="stat-points">0</span>
                        </div>
                    </div>
                </div>
                
                <div class="panel-section">
                    <h3>📤 Export Data</h3>
                    <button class="btn btn-success" style="width: 100%; margin-bottom: 10px;" onclick="exportGeoJSON()">
                        Export as GeoJSON
                    </button>
                    <button class="btn btn-primary" style="width: 100%; margin-bottom: 10px;" onclick="exportKML()">
                        Export as KML
                    </button>
                    <button class="btn btn-secondary" style="width: 100%; margin-bottom: 10px;" onclick="copyCoordinates()">
                        Copy All Coordinates
                    </button>
                    <div id="exportPreview" class="export-box">
                        Click export to see data
                    </div>
                </div>
                
                <div class="panel-section">
                    <h3>➕ Add Point Manually</h3>
                    <input type="text" id="manualLat" class="coord-input" placeholder="Latitude (e.g., 0.61791)" value="0.61791">
                    <input type="text" id="manualLng" class="coord-input" placeholder="Longitude (e.g., 34.52427)" value="34.52427">
                    <button class="btn btn-primary" style="width: 100%;" onclick="addManualPoint()">
                        Add Point
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Initialize map
        var map = L.map('map').setView([0.61791, 34.52427], 16);
        
        // Add base map
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap contributors'
        }).addTo(map);
        
        // Add satellite view option
        var satellite = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
            attribution: '© Esri'
        });
        
        // Add base marker for university
        var universityMarker = L.marker([0.61791, 34.52427]).addTo(map);
        universityMarker.bindPopup('<b>Kibabii University</b><br>0.61791°N, 34.52427°E').openPopup();
        
        // Add circle for campus area
        var campusCircle = L.circle([0.61791, 34.52427], {
            color: 'blue',
            fillColor: '#30f',
            fillOpacity: 0.1,
            radius: 300
        }).addTo(map);
        campusCircle.bindPopup('University Campus (~28 hectares)');
        
        // Initialize FeatureGroup to store drawn items
        var drawnItems = new L.FeatureGroup();
        map.addLayer(drawnItems);
        
        // Initialize draw control
        var drawControl = new L.Control.Draw({
            edit: {
                featureGroup: drawnItems,
                edit: true,
                remove: true
            },
            draw: {
                polygon: true,
                polyline: true,
                rectangle: true,
                circle: true,
                marker: true
            }
        });
        map.addControl(drawControl);
        
        // Store coordinates data
        var coordinates = [];
        var currentFormat = 'decimal';
        
        // Convert DMS to decimal
        function dmsToDecimal(degrees, minutes, seconds, direction) {
            var decimal = degrees + minutes/60 + seconds/3600;
            if (direction === 'S' || direction === 'W') {
                decimal = -decimal;
            }
            return decimal;
        }
        
        // Convert decimal to DMS
        function toDMS(coord, isLat) {
            var absolute = Math.abs(coord);
            var degrees = Math.floor(absolute);
            var minutes = Math.floor((absolute - degrees) * 60);
            var seconds = ((absolute - degrees - minutes/60) * 3600).toFixed(2);
            
            var direction;
            if (isLat) {
                direction = coord >= 0 ? 'N' : 'S';
            } else {
                direction = coord >= 0 ? 'E' : 'W';
            }
            
            return degrees + '°' + minutes + "'" + seconds + '"' + direction;
        }
        
        // Format coordinate based on current format
        function formatCoordinate(lat, lng) {
            if (currentFormat === 'decimal') {
                return lat.toFixed(6) + '°N, ' + lng.toFixed(6) + '°E';
            } else {
                return toDMS(lat, true) + ', ' + toDMS(lng, false);
            }
        }
        
        // Update statistics
        function updateStats() {
            var markers = 0;
            var lines = 0;
            var polygons = 0;
            var totalPoints = 0;
            
            drawnItems.eachLayer(function(layer) {
                if (layer instanceof L.Marker) {
                    markers++;
                    totalPoints++;
                } else if (layer instanceof L.Polyline && !(layer instanceof L.Polygon)) {
                    lines++;
                    totalPoints += layer.getLatLngs().length;
                } else if (layer instanceof L.Polygon) {
                    polygons++;
                    totalPoints += layer.getLatLngs()[0].length;
                } else if (layer instanceof L.Circle) {
                    polygons++;
                    totalPoints++;
                }
            });
            
            document.getElementById('stat-markers').textContent = markers;
            document.getElementById('stat-lines').textContent = lines;
            document.getElementById('stat-polygons').textContent = polygons;
            document.getElementById('stat-points').textContent = totalPoints;
        }
        
        // Update coordinates list
        function updateCoordinatesList() {
            var list = document.getElementById('coordinatesList');
            coordinates = [];
            
            drawnItems.eachLayer(function(layer) {
                if (layer instanceof L.Marker) {
                    var latlng = layer.getLatLng();
                    coordinates.push({
                        type: 'marker',
                        lat: latlng.lat,
                        lng: latlng.lng,
                        formatted: formatCoordinate(latlng.lat, latlng.lng)
                    });
                } else if (layer instanceof L.Polygon) {
                    var latlngs = layer.getLatLngs()[0];
                    latlngs.forEach(function(latlng, index) {
                        coordinates.push({
                            type: 'polygon',
                            index: index,
                            lat: latlng.lat,
                            lng: latlng.lng,
                            formatted: formatCoordinate(latlng.lat, latlng.lng)
                        });
                    });
                } else if (layer instanceof L.Polyline) {
                    var latlngs = layer.getLatLngs();
                    latlngs.forEach(function(latlng, index) {
                        coordinates.push({
                            type: 'line',
                            index: index,
                            lat: latlng.lat,
                            lng: latlng.lng,
                            formatted: formatCoordinate(latlng.lat, latlng.lng)
                        });
                    });
                }
            });
            
            if (coordinates.length === 0) {
                list.innerHTML = '<p style="color: #7f8c8d; text-align: center;">No coordinates recorded yet</p>';
                return;
            }
            
            var html = '';
            coordinates.forEach(function(coord, i) {
                html += '<div class="coordinate-item">';
                html += '<div class="coord-header">';
                html += '<span>' + coord.type + (coord.index !== undefined ? ' #' + (coord.index + 1) : '') + '</span>';
                html += '<div class="coord-actions">';
                html += '<button class="btn btn-sm btn-primary" onclick="copyCoordinate(' + i + ')">📋</button>';
                html += '<button class="btn btn-sm btn-danger" onclick="removeCoordinate(' + i + ')">✖</button>';
                html += '</div>';
                html += '</div>';
                html += '<div>' + coord.formatted + '</div>';
                html += '</div>';
            });
            
            list.innerHTML = html;
        }
        
        // Handle draw events
        map.on(L.Draw.Event.CREATED, function(event) {
            var layer = event.layer;
            drawnItems.addLayer(layer);
            
            // Add popup with coordinates
            if (layer instanceof L.Marker) {
                var latlng = layer.getLatLng();
                layer.bindPopup('Marker<br>' + formatCoordinate(latlng.lat, latlng.lng));
            } else if (layer instanceof L.Polygon) {
                var latlngs = layer.getLatLngs()[0];
                var points = latlngs.map(ll => formatCoordinate(ll.lat, ll.lng)).join('<br>');
                layer.bindPopup('Polygon<br>' + points);
            } else if (layer instanceof L.Polyline) {
                var latlngs = layer.getLatLngs();
                var points = latlngs.map(ll => formatCoordinate(ll.lat, ll.lng)).join('<br>');
                layer.bindPopup('Line<br>' + points);
            } else if (layer instanceof L.Circle) {
                var latlng = layer.getLatLng();
                layer.bindPopup('Circle<br>Center: ' + formatCoordinate(latlng.lat, latlng.lng) + '<br>Radius: ' + layer.getRadius() + 'm');
            }
            
            updateCoordinatesList();
            updateStats();
        });
        
        // Handle edit events
        map.on(L.Draw.Event.EDITED, function(event) {
            updateCoordinatesList();
            updateStats();
        });
        
        map.on(L.Draw.Event.DELETED, function(event) {
            updateCoordinatesList();
            updateStats();
        });
        
        // Enable draw mode
        function enableDrawMode(type) {
            switch(type) {
                case 'marker':
                    new L.Draw.Marker(map).enable();
                    break;
                case 'polyline':
                    new L.Draw.Polyline(map).enable();
                    break;
                case 'polygon':
                    new L.Draw.Polygon(map).enable();
                    break;
                case 'rectangle':
                    new L.Draw.Rectangle(map).enable();
                    break;
                case 'circle':
                    new L.Draw.Circle(map).enable();
                    break;
            }
        }
        
        // Clear all drawn items
        function clearAll() {
            drawnItems.clearLayers();
            updateCoordinatesList();
            updateStats();
        }
        
        // Center on university
        function centerOnUniversity() {
            map.setView([0.61791, 34.52427], 16);
        }
        
        // Set coordinate format
        function setFormat(format) {
            currentFormat = format;
            document.getElementById('format-decimal').classList.toggle('active', format === 'decimal');
            document.getElementById('format-dms').classList.toggle('active', format === 'dms');
            updateCoordinatesList();
        }
        
        // Copy single coordinate
        function copyCoordinate(index) {
            if (coordinates[index]) {
                var text = coordinates[index].formatted;
                navigator.clipboard.writeText(text).then(function() {
                    alert('Coordinate copied: ' + text);
                });
            }
        }
        
        // Remove single coordinate
        function removeCoordinate(index) {
            // This is complex - would need to find and remove the actual layer
            // For now, just alert
            alert('To remove, click on the shape in the map and use the delete tool');
        }
        
        // Add manual point
        function addManualPoint() {
            var lat = parseFloat(document.getElementById('manualLat').value);
            var lng = parseFloat(document.getElementById('manualLng').value);
            
            if (isNaN(lat) || isNaN(lng)) {
                alert('Please enter valid coordinates');
                return;
            }
            
            var marker = L.marker([lat, lng]).addTo(drawnItems);
            marker.bindPopup('Manual Point<br>' + formatCoordinate(lat, lng));
            updateCoordinatesList();
            updateStats();
        }
        
        // Export as GeoJSON
        function exportGeoJSON() {
            var geojson = drawnItems.toGeoJSON();
            document.getElementById('exportPreview').textContent = JSON.stringify(geojson, null, 2);
            
            // Download
            var dataStr = "data:text/json;charset=utf-8," + encodeURIComponent(JSON.stringify(geojson, null, 2));
            var downloadAnchor = document.createElement('a');
            downloadAnchor.setAttribute("href", dataStr);
            downloadAnchor.setAttribute("download", "kibabii_drawing.geojson");
            document.body.appendChild(downloadAnchor);
            downloadAnchor.click();
            downloadAnchor.remove();
        }
        
        // Export as KML
        function exportKML() {
            var kml = '<?xml version="1.0" encoding="UTF-8"?>\n';
            kml += '<kml xmlns="http://www.opengis.net/kml/2.2">\n';
            kml += '<Document>\n';
            kml += '<name>Kibabii University Drawings</name>\n';
            
            drawnItems.eachLayer(function(layer) {
                if (layer instanceof L.Marker) {
                    var latlng = layer.getLatLng();
                    kml += '<Placemark>\n';
                    kml += '<name>Marker</name>\n';
                    kml += '<Point>\n';
                    kml += '<coordinates>' + latlng.lng + ',' + latlng.lat + ',0</coordinates>\n';
                    kml += '</Point>\n';
                    kml += '</Placemark>\n';
                } else if (layer instanceof L.Polygon) {
                    kml += '<Placemark>\n';
                    kml += '<name>Polygon</name>\n';
                    kml += '<Polygon>\n';
                    kml += '<outerBoundaryIs>\n';
                    kml += '<LinearRing>\n';
                    kml += '<coordinates>\n';
                    var latlngs = layer.getLatLngs()[0];
                    latlngs.forEach(function(ll) {
                        kml += ll.lng + ',' + ll.lat + ',0\n';
                    });
                    // Close the ring
                    kml += latlngs[0].lng + ',' + latlngs[0].lat + ',0\n';
                    kml += '</coordinates>\n';
                    kml += '</LinearRing>\n';
                    kml += '</outerBoundaryIs>\n';
                    kml += '</Polygon>\n';
                    kml += '</Placemark>\n';
                }
            });
            
            kml += '</Document>\n';
            kml += '</kml>';
            
            document.getElementById('exportPreview').textContent = kml;
            
            var dataStr = "data:text/xml;charset=utf-8," + encodeURIComponent(kml);
            var downloadAnchor = document.createElement('a');
            downloadAnchor.setAttribute("href", dataStr);
            downloadAnchor.setAttribute("download", "kibabii_drawing.kml");
            document.body.appendChild(downloadAnchor);
            downloadAnchor.click();
            downloadAnchor.remove();
        }
        
        // Copy all coordinates
        function copyCoordinates() {
            var text = coordinates.map(c => c.formatted).join('\n');
            navigator.clipboard.writeText(text).then(function() {
                alert('All coordinates copied to clipboard');
            });
        }
        
        // Initialize stats
        updateStats();
    </script>
</body>
</html>
const fs = require('fs');
const base = 'C:/Users/asus/Desktop/bounty-hunter/hyperlane-monorepo/coolify/';
const BS = String.fromCharCode(92); // backslash

function addUseStatement(content, after, toAdd) {
  const needle = 'use App' + BS + 'Models' + BS + after + ';';
  const replacement = 'use App' + BS + 'Models' + BS + after + ';\n' + toAdd;
  if (!content.includes('StandaloneSurrealDB')) {
    content = content.replace(needle, replacement);
  }
  return content;
}

function addToUnion(content) {
  return content.replace('StandaloneClickhouse $database', 'StandaloneClickhouse|StandaloneSurrealDB $database');
}
function addToUnionResource(content) {
  return content.replace('StandaloneClickhouse $resource', 'StandaloneClickhouse|StandaloneSurrealDB $resource');
}
function addToUnionService(content) {
  return content.replace('StandaloneClickhouse|ServiceDatabase', 'StandaloneClickhouse|StandaloneSurrealDB|ServiceDatabase');
}

// 1. Enum
let enumFile = fs.readFileSync(base + 'app/Enums/NewDatabaseTypes.php', 'utf8');
if (!enumFile.includes('SURREALDB')) {
  enumFile = enumFile.replace("case CLICKHOUSE = 'clickhouse';", "case CLICKHOUSE = 'clickhouse';\n    case SURREALDB = 'surrealdb';");
  fs.writeFileSync(base + 'app/Enums/NewDatabaseTypes.php', enumFile);
  console.log('enum done');
}

// 2. StartDatabase
let sd = fs.readFileSync(base + 'app/Actions/Database/StartDatabase.php', 'utf8');
sd = addUseStatement(sd, 'StandaloneKeydb', "use App" + BS + "Models" + BS + "StandaloneSurrealDB;");
sd = addToUnion(sd);
if (!sd.includes("StartSurrealDB")) {
  sd = sd.replace(
    "case " + BS + "App" + BS + "Models" + BS + "StandaloneClickhouse::class:\n                $activity = StartClickhouse::run($database);\n                break;",
    "case " + BS + "App" + BS + "Models" + BS + "StandaloneClickhouse::class:\n                $activity = StartClickhouse::run($database);\n                break;\n            case " + BS + "App" + BS + "Models" + BS + "StandaloneSurrealDB::class:\n                $activity = StartSurrealDB::run($database);\n                break;"
  );
}
fs.writeFileSync(base + 'app/Actions/Database/StartDatabase.php', sd);
console.log('StartDatabase done');

// 3. RestartDatabase
let rd = fs.readFileSync(base + 'app/Actions/Database/RestartDatabase.php', 'utf8');
rd = addUseStatement(rd, 'StandaloneKeydb', "use App" + BS + "Models" + BS + "StandaloneSurrealDB;");
rd = addToUnion(rd);
fs.writeFileSync(base + 'app/Actions/Database/RestartDatabase.php', rd);
console.log('RestartDatabase done');

// 4. StopDatabase
let std = fs.readFileSync(base + 'app/Actions/Database/StopDatabase.php', 'utf8');
std = addUseStatement(std, 'StandaloneKeydb', "use App" + BS + "Models" + BS + "StandaloneSurrealDB;");
std = addToUnion(std);
fs.writeFileSync(base + 'app/Actions/Database/StopDatabase.php', std);
console.log('StopDatabase done');

// 5. StartDatabaseProxy
let sdp = fs.readFileSync(base + 'app/Actions/Database/StartDatabaseProxy.php', 'utf8');
sdp = addUseStatement(sdp, 'StandaloneClickhouse', "use App" + BS + "Models" + BS + "StandaloneSurrealDB;");
sdp = addToUnionService(sdp);
fs.writeFileSync(base + 'app/Actions/Database/StartDatabaseProxy.php', sdp);
console.log('StartDatabaseProxy done');

// 6. StopDatabaseProxy
let stdp = fs.readFileSync(base + 'app/Actions/Database/StopDatabaseProxy.php', 'utf8');
stdp = addUseStatement(stdp, 'StandaloneClickhouse', "use App" + BS + "Models" + BS + "StandaloneSurrealDB;");
stdp = addToUnion(stdp);
fs.writeFileSync(base + 'app/Actions/Database/StopDatabaseProxy.php', stdp);
console.log('StopDatabaseProxy done');

// 7. DeleteResourceJob
let drj = fs.readFileSync(base + 'app/Jobs/DeleteResourceJob.php', 'utf8');
drj = addUseStatement(drj, 'StandaloneKeydb', "use App" + BS + "Models" + BS + "StandaloneSurrealDB;");
drj = addToUnionResource(drj);
if (!drj.includes("standalone-surrealdb")) {
  drj = drj.replace(
    "case 'standalone-clickhouse':",
    "case 'standalone-clickhouse':\n                case 'standalone-surrealdb':"
  );
}
if (!drj.includes("StandaloneSurrealDB;")) {
  drj = drj.replace(
    "|| $this->resource instanceof StandaloneClickhouse;",
    "|| $this->resource instanceof StandaloneClickhouse\n            || $this->resource instanceof StandaloneSurrealDB;"
  );
}
fs.writeFileSync(base + 'app/Jobs/DeleteResourceJob.php', drj);
console.log('DeleteResourceJob done');

// 8. FileStorage
let fl = fs.readFileSync(base + 'app/Livewire/Project/Service/FileStorage.php', 'utf8');
fl = addUseStatement(fl, 'StandaloneKeydb', "use App" + BS + "Models" + BS + "StandaloneSurrealDB;");
fl = addToUnionService(fl);
fs.writeFileSync(base + 'app/Livewire/Project/Service/FileStorage.php', fl);
console.log('FileStorage done');

// 9. CleanupNames
let cn = fs.readFileSync(base + 'app/Console/Commands/CleanupNames.php', 'utf8');
if (!cn.includes('StandaloneSurrealDB')) {
  cn = cn.replace(
    "'StandaloneKeydb' => StandaloneKeydb::class,",
    "'StandaloneKeydb' => StandaloneKeydb::class,\n        'StandaloneSurrealDB' => StandaloneSurrealDB::class,"
  );
  cn = addUseStatement(cn, 'StandaloneKeydb', "use App" + BS + "Models" + BS + "StandaloneSurrealDB;");
}
fs.writeFileSync(base + 'app/Console/Commands/CleanupNames.php', cn);
console.log('CleanupNames done');

// 10. Select.php - add to database list and switch case
let sel = fs.readFileSync(base + 'app/Livewire/Project/New/Select.php', 'utf8');
// add database entry after clickhouse
if (!sel.includes('surrealdb')) {
  sel = sel.replace(
    "['id' => 'clickhouse',",
    "['id' => 'surrealdb',\n                'name' => 'SurrealDB',\n                'description' => 'Multi-model database with real-time capabilities.',\n                'logo' => '<div class=\"w-[4.5rem] h-[4.5rem] p-2 flex items-center justify-center text-2xl font-bold\">SDB</div>',\n            ],\n            [\n                'id' => 'clickhouse',"
  );
  // add switch case
  sel = sel.replace(
    "case 'clickhouse':",
    "case 'surrealdb':\n            case 'clickhouse':"
  );
}
fs.writeFileSync(base + 'app/Livewire/Project/New/Select.php', sel);
console.log('Select.php done');

console.log('\\nAll changes applied');

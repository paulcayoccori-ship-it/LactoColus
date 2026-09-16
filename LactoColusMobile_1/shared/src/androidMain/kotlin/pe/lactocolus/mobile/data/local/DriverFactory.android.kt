package pe.lactocolus.mobile.data.local

import android.content.Context
import app.cash.sqldelight.driver.android.AndroidSqliteDriver
import app.cash.sqldelight.db.SqlDriver
import pe.lactocolus.mobile.db.LactoColusDb

actual class DriverFactory(private val context: Context) {
    actual fun createDriver(): SqlDriver =
        AndroidSqliteDriver(LactoColusDb.Schema, context, "lactocolus.db")
}

# Create Excel template with dropdowns
Add-Type -AssemblyName Microsoft.Office.Interop.Excel

$excel = New-Object -ComObject Excel.Application
$excel.Visible = $false
$workbook = $excel.Workbooks.Add()
$worksheet = $workbook.Sheets(1)
$worksheet.Name = "Students"

# Add headers
$headers = @("student_id", "full_name", "email", "phone", "course", "year_level", "section")
for ($i = 0; $i -lt $headers.Count; $i++) {
    $worksheet.Cells.Item(1, $i + 1) = $headers[$i]
}

# Style header row (blue background, white text, bold)
$worksheet.Range("A1:G1").Interior.Color = 4472516
$worksheet.Range("A1:G1").Font.Bold = $true
$worksheet.Range("A1:G1").Font.Color = 16777215

# Add sample data
$sampleData = @(
    @("22013938", "Justine Martin", "martin@gmail.com", "9933447697", "bsis", "4", "c"),
    @("20013456", "Juan Santos", "juan@gmail.com", "9912345678", "bsom", "2", "b"),
    @("21005789", "Maria Cruz", "maria@gmail.com", "9923456789", "bsais", "3", "a")
)

$row = 2
foreach ($data in $sampleData) {
    for ($i = 0; $i -lt $data.Count; $i++) {
        $worksheet.Cells.Item($row, $i + 1) = $data[$i]
    }
    $row++
}

# Add empty rows
for ($i = $row; $i -le 21; $i++) {
    for ($j = 1; $j -le 7; $j++) {
        $worksheet.Cells.Item($i, $j) = ""
    }
}

# Add data validation for Course column (E2:E21)
$dv1 = $worksheet.Range("E2:E21").Validation
$dv1.Delete()
$dv1 = $worksheet.Range("E2:E21").Validation
$dv1.Add([Microsoft.Office.Interop.Excel.XlDVType]::xlList, [Microsoft.Office.Interop.Excel.XlDVAlertStyle]::xlValidAlertInformation, [Microsoft.Office.Interop.Excel.XlFormulaLabel]::xlHideLabels, "bsis,bsom,bsais,bscs,bsme")
$dv1.ShowDropDown = $true
$dv1.IgnoreBlank = $false

# Add data validation for Year Level column (F2:F21)
$dv2 = $worksheet.Range("F2:F21").Validation
$dv2.Delete()
$dv2 = $worksheet.Range("F2:F21").Validation
$dv2.Add([Microsoft.Office.Interop.Excel.XlDVType]::xlList, [Microsoft.Office.Interop.Excel.XlDVAlertStyle]::xlValidAlertInformation, [Microsoft.Office.Interop.Excel.XlFormulaLabel]::xlHideLabels, "1,2,3,4")
$dv2.ShowDropDown = $true
$dv2.IgnoreBlank = $false

# Add data validation for Section column (G2:G21)
$dv3 = $worksheet.Range("G2:G21").Validation
$dv3.Delete()
$dv3 = $worksheet.Range("G2:G21").Validation
$dv3.Add([Microsoft.Office.Interop.Excel.XlDVType]::xlList, [Microsoft.Office.Interop.Excel.XlDVAlertStyle]::xlValidAlertInformation, [Microsoft.Office.Interop.Excel.XlFormulaLabel]::xlHideLabels, "a,b,c,d,e")
$dv3.ShowDropDown = $true
$dv3.IgnoreBlank = $false

# Set column widths
$worksheet.Columns("A").ColumnWidth = 15
$worksheet.Columns("B").ColumnWidth = 20
$worksheet.Columns("C").ColumnWidth = 25
$worksheet.Columns("D").ColumnWidth = 15
$worksheet.Columns("E").ColumnWidth = 15
$worksheet.Columns("F").ColumnWidth = 12
$worksheet.Columns("G").ColumnWidth = 10

# Freeze panes
$worksheet.Range("A2:A2").Select()
$excel.ActiveWindow.FreezePanes = $true

# Save the file
$filePath = "c:\xampp\htdocs\unineed\assets\templates\students_template.xlsx"
$workbook.SaveAs($filePath, 51)

# Cleanup
$excel.Quit()
[System.Runtime.InteropServices.Marshal]::ReleaseComObject($excel) | Out-Null

Write-Host "Excel template with dropdowns created successfully at: $filePath"

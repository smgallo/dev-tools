# Generate a histogram
#
# Data is expected to be vertical with series separated by 2 blank lines with 3 columns:
# series_name, datetime, value
#
# See: http://lowrank.net/gnuplot/datafile2-e.html
#
# For example:
# federated_osg   2018-09-15 15:40:00     11.1864
# federated_osg   2018-09-15 16:01:00     27.819
#
#
# github  2018-09-16 8:00:00      12.4766
# github  2018-09-16 9:00:00      4.82028
#
#
# mod_appkernel   2018-09-16 1:30:00      97.0255
# mod_appkernel   2018-09-16 1:31:00      96.6372
#
# See also:
# See http://torbiak.com/post/histogram_gnuplot_vs_matplotlib/#the-data
# See http://lowrank.net/gnuplot/datafile2-e.html
# See http://www.gnuplot.info/docs_5.2/Gnuplot_5.2.pdf

clear
reset
set style data histogram
# We want a stacked histogram with a black border around each series
set style histogram rowstacked
set style fill solid border linecolor rgb "black"
# Display time on xaxis but only hours and minutes
set xtics rotate out nomirror
set yrange [0:3300] noreverse nowriteback
set title "Statements per minute (log)"

# print to stdout
set print "-"

# Use tab as separator
set datafile separator "\t"

# Since we have more than 8 series define our own colors using the XDMoD color palette

colors="#1199FF #DB4230 #4E665D #F4A221 #66FF00 #33ABAB #A88D95 #789ABC #FF99CC #00CCFF #FFBC71 #A57E81 #8D4DFF #FF6666 #CC99FF #2F7ED8 #0D233A #8BBC21 #910000 #1AADCE #492970 #F28F43 #77A1E5 #3366FF #FF6600 #808000 #CC99FF #008080 #CC6600 #9999FF #99FF99 #969696 #FF00FF #FFCC00 #666699 #00FFFF #00CCFF #993366 #3AAAAA #C0C0C0 #FF99CC #FFCC99 #CCFFCC #CCFFFF #99CCFF #339966 #FF9966 #69BBED #33FF33 #6666FF #FF66FF #99ABAB #AB8722 #AB6565 #990099 #999900 #CC3300 #669999 #993333 #339966 #C42525 #A6C96A #111111"

do for [style_num=1:words(colors):1] {
    set style line style_num linecolor rgb word(colors, style_num)
}

# Get the list of unique series names
series_names=system("awk '{print $1}' 2018-09-16-ingest-plot.txt|uniq")

# Plot the data. Since data is vertical rather than horizontal we separate each
# series with 2 blank lines and use "index" rather than "using x:y" to address it.
# The for loop takes is shorthand for repeating everything that comes after it using variables

plot for [s=0:words(series_names)-1:1] "2018-09-16-ingest-plot.dat" index s using 3:xticlabels(2) title word(series_names, s+1) linestyle s+1


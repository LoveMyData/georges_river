require 'scraperwiki'
require 'mechanize'

# Extending Mechanize Form to support doPostBack
# http://scraperblog.blogspot.com.au/2012/10/asp-forms-with-dopostback-using-ruby.html
class Mechanize::Form
  def postback target, argument
    self['__EVENTTARGET'], self['__EVENTARGUMENT'] = target, argument
    submit
  end
end


def process_page(page, base_url, comment_url)
  page.search('tr.rgRow,tr.rgAltRow').each do |tr|
    record = {
      "council_reference" => tr.search('td')[1].inner_text.gsub("\r\n", "").strip,
      "address" => tr.search('td')[3].search('strong')[0].inner_text.strip + ', NSW',
      "description" => tr.search('td')[3].inner_html.gsub("\r", " ").strip.split("<br>")[1],
      "info_url" => base_url + tr.search('td').at('a')["href"].to_s,
      "comment_url" => comment_url,
      "date_scraped" => Date.today.to_s,
      "date_received" => Date.parse(tr.search('td')[2].inner_text.gsub("\r\n", "").strip).to_s,
    }

    if (ScraperWiki.select("* from data where `council_reference`='#{record['council_reference']}'").empty? rescue true)
      puts "Saving record " + record['council_reference'] + " - " + record['address']
#       puts record
      ScraperWiki.save_sqlite(['council_reference'], record)
    else
      puts "Skipping already saved record " + record['council_reference']
    end
  end
end


case ENV['MORPH_PERIOD']
  when 'lastmonth'
    period = 'lastmonth'
  when 'thismonth'
    period = 'thismonth'
  else
    period = 'thisweek'
end
puts "Getting data in `" + period + "`, changable via MORPH_PERIOD variable"

base_url = "https://daenquiry.georgesriver.nsw.gov.au/masterviewui/Modules/applicationmaster/"
url = base_url + "default.aspx?page=found&1=" + period + "&4a=DA%27,%27S96Mods%27,%27Mods%27,%27Reviews&6=F"
comment_url = "mailto:mail@georgesriver.nsw.gov.au"

agent = Mechanize.new
agent.verify_mode = OpenSSL::SSL::VERIFY_NONE
page = agent.get(url)

page.form["ctl00$cphContent$ctl00$Button1"] = "Agreed"
page = page.form.submit
page = agent.get(url)


if page.search('div.rgNumPart a').empty?
  process_page(page, base_url, comment_url)
else
  i = 1
  page.search('div.rgNumPart a').each do |a|
    puts "scraping page " + i.to_s
    target, argument = a[:href].scan(/'([^']*)'/).flatten
    page = page.form.postback target, argument

    process_page(page, base_url, comment_url)
    i += 1
  end
end

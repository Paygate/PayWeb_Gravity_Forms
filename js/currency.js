//------------------------------------------------
//---------- CURRENCY ----------------------------
//------------------------------------------------
function Currency(currency) {
  this.currency = currency

  this.toNumber = function (text) {
    if (this.isNumeric(text))
      return parseFloat(text)

    //converting to a string if a number as passed
    text = text + ' '

    //Removing symbol in unicode format (i.e. &#4444;)
    text = text.replace(/&.*?;/, '', text)

    //Removing all non-numeric characters
    var clean_number = ''
    var is_negative = false
    for (let i = 0; i < text.length; i++) {
      var digit = text.substr(i, 1)
      if ((parseInt(digit) >= 0 && parseInt(digit) <= 9) || digit == ',' || digit == '.')
        clean_number += digit
      else if (digit == '-')
        is_negative = true
    }

    //Removing thousand separators but keeping decimal point
    var float_number = ''
    var decimal_separator = this.currency && this.currency['decimal_separator'] ? this.currency['decimal_separator'] : '.'

    for (let j = 0; j < clean_number.length; j++) {
      var char = clean_number.substr(j, 1)

      if (char >= '0' && char <= '9')
        float_number += char
      else if (char == decimal_separator) {
        float_number += '.'
      }
    }

    if (is_negative)
      float_number = '-' + float_number

    return this.isNumeric(float_number) ? parseFloat(float_number) : false
  }

  this.toMoney = function (number) {
    if (!this.isNumeric(number))
      number = this.toNumber(number)

    if (number === false)
      return ''

    number = number + ''
    let negative = ''
    if (number[0] == '-') {
      negative = '-'
      number = parseFloat(number.substr(1))
    }
    let money = this.numberFormat(number, this.currency['decimals'], this.currency['decimal_separator'], this.currency['thousand_separator'])

    var symbol_left = this.currency['symbol_left'] ? this.currency['symbol_left'] + this.currency['symbol_padding'] : ''
    var symbol_right = this.currency['symbol_right'] ? this.currency['symbol_padding'] + this.currency['symbol_right'] : ''
    money = negative + this.htmlDecode(symbol_left) + money + this.htmlDecode(symbol_right)
    return money
  }

  this.numberFormat = function (number, decimals, dec_point, thousands_sep) {
    number = (number + '').replace(',', '').replace(' ', '')
    var num = !isFinite(+number) ? 0 : +number,
      precedes = !isFinite(+decimals) ? 0 : Math.abs(decimals),
      sep = (typeof thousands_sep === 'undefined') ? ',' : thousands_sep,
      dec = (typeof dec_point === 'undefined') ? '.' : dec_point,
      s = '',

      toFixedFix = function (n, prec) {
        var k = Math.pow(10, prec)
        return '' + Math.round(n * k) / k
      }

    // Fix for IE parseFloat(0.55).toFixed(0) = 0;
    s = (precedes ? toFixedFix(num, precedes) : '' + Math.round(num)).split('.')
    if (s[0].length > 3) {
      s[0] = s[0].replace(/\B(?=(?:\d{3})+(?!\d))/g, sep)
    }

    if ((s[1] || '').length < precedes) {
      s[1] = s[1] || ''
      s[1] += new Array(precedes - s[1].length + 1).join('0')
    }

    return s.join(dec)
  }

  this.isNumeric = function (number) {
    return !isNaN(parseFloat(number)) && isFinite(number)
  }

  this.htmlDecode = function (text) {
    var c, m, d = text

    // look for numerical entities &#34;
    var arr = d.match(/&#\d{1,5};/g)

    // if no matches found in string then skip
    if (arr != null) {
      for (let value of arr) {
        m = value
        c = m.substring(2, m.length - 1) //get numeric part which is refernce to unicode character
        // if its a valid number we can decode
        if (c >= -32768 && c <= 65535) {
          // decode every single match within string
          d = d.replace(m, String.fromCharCode(c))
        } else {
          d = d.replace(m, '') //invalid so replace with nada
        }
      }
    }
    return d
  }
}

jQuery(document).ready(function () {
  jQuery('#gform_3 .gform_heading').append('<div class="validation_error">There was a problem with your submission. Errors have been highlighted below.</div>')
})
